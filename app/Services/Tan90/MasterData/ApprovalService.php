<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\ApprovalProgress;
use App\Models\Tan90\MasterData\ApprovalStepDecision;
use App\Models\Tan90\MasterData\ApprovalWorkflow;
use App\Models\Tan90\MasterData\ApprovalWorkflowStep;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Models\Tan90\MasterData\MasterChangeVersion;
use App\Models\Tan90\MasterData\Role;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Maker-checker state machine, now with real multi-step routing.
 *
 * submit() opens an ApprovalProgress row for every entity (used for SLA
 * tracking regardless of workflow). If the entity's title matches an active
 * tan90_approval_workflow's `module`, the record also gets routed through
 * that workflow's ordered tan90_approval_workflow_steps: approve() only
 * finalizes approval_status='approved' once every step has signed off, and
 * each step is (softly) gated to the matching Tan90 Role by name - if no
 * Tan90 Role exists with that step's name, the gate is skipped rather than
 * making the workflow un-completable (see docs/PHASE_2_SCOPE.md).
 *
 * Once a record's approval_status is "approved", editing one of its
 * `critical_fields` (as declared in config/tan90_master_data.php) must not
 * mutate the row directly: requestCriticalChange() opens a
 * MasterChangeRequest instead, and approveChangeRequest() is the only path
 * that applies the change and writes an effective-dated MasterChangeVersion.
 */
class ApprovalService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NotificationDispatcher $notifications,
    ) {
    }

    public function submit(array $entity, Model $record): Model
    {
        $this->assertHasApprovalStatus($record);

        $record->approval_status = 'review';
        $record->save();

        $this->auditLogger->log('SUBMIT', $record, "Submitted {$record->auditLabel()} for approval.");

        $workflow = ApprovalWorkflow::where('module', $entity['title'])->where('approval_status', 'active')->first();
        $firstStep = $workflow
            ? ApprovalWorkflowStep::where('tan90_approval_workflow_id', $workflow->id)->orderBy('step_order')->first()
            : null;

        ApprovalProgress::updateOrCreate(
            ['entity_type' => $entity['slug'], 'entity_id' => $record->getKey()],
            [
                'tan90_approval_workflow_id' => $workflow?->id,
                'current_step_order' => $firstStep?->step_order,
                'status' => 'pending',
                'submitted_at' => now(),
                'sla_warned_at' => null,
                'sla_escalated_at' => null,
            ]
        );

        $this->notifications->sendToApprovers('NT-MASTER-APPROVAL', [
            'record_type' => class_basename($record),
            'record_code' => $record->auditLabel(),
            'summary' => class_basename($record) . ' ' . $record->auditLabel()
                . ($firstStep ? " needs '{$firstStep->step_role}' approval (step 1 of the {$workflow->name} workflow)." : ' was submitted and needs approval.'),
        ]);

        return $record;
    }

    /**
     * @throws DomainException if a multi-step workflow is in progress and $approver's
     *                          role doesn't match the current step's required role
     */
    public function approve(array $entity, Model $record, User $approver): Model
    {
        $this->assertHasApprovalStatus($record);

        $progress = $this->openProgressFor($entity, $record);

        if ($progress?->tan90_approval_workflow_id) {
            return $this->advanceWorkflowStep($entity, $record, $progress, $approver);
        }

        $progress?->update(['status' => 'approved']);

        $record->approval_status = 'approved';
        $record->save();

        $this->auditLogger->log('APPROVE', $record, "Approved {$record->auditLabel()} through maker-checker review.");

        return $record;
    }

    public function reject(array $entity, Model $record, User $approver, ?string $notes = null): Model
    {
        $this->assertHasApprovalStatus($record);

        $progress = $this->openProgressFor($entity, $record);
        if ($progress) {
            $progress->update(['status' => 'rejected']);
            ApprovalStepDecision::create([
                'tan90_approval_progress_id' => $progress->id,
                'tan90_approval_workflow_step_id' => $progress->currentStep()?->id,
                'decided_by' => $approver->id,
                'decision' => 'rejected',
                'notes' => $notes,
                'decided_at' => now(),
            ]);
        }

        $record->approval_status = 'rejected';
        $record->save();

        $this->auditLogger->log('REJECT', $record, trim("Rejected {$record->auditLabel()}. {$notes}"));

        return $record;
    }

    private function advanceWorkflowStep(array $entity, Model $record, ApprovalProgress $progress, User $approver): Model
    {
        $step = $progress->currentStep();

        if ($step && $this->stepRoleBlocksApprover($approver, $step->step_role)) {
            throw new DomainException("This record is awaiting approval from the '{$step->step_role}' role at its current workflow step.");
        }

        ApprovalStepDecision::create([
            'tan90_approval_progress_id' => $progress->id,
            'tan90_approval_workflow_step_id' => $step?->id,
            'decided_by' => $approver->id,
            'decision' => 'approved',
            'decided_at' => now(),
        ]);

        $nextStep = $step
            ? ApprovalWorkflowStep::where('tan90_approval_workflow_id', $progress->tan90_approval_workflow_id)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->first()
            : null;

        if ($nextStep) {
            $progress->update(['current_step_order' => $nextStep->step_order]);
            $this->auditLogger->log(
                'STEP_APPROVE',
                $record,
                "Step '" . ($step->step_role ?? 'n/a') . "' approved {$record->auditLabel()}; advanced to step '{$nextStep->step_role}'."
            );

            return $record; // still "review" until the final step signs off
        }

        // Final step (or a workflow with no steps at all): finalize.
        $progress->update(['status' => 'approved']);
        $record->approval_status = 'approved';
        $record->save();

        $this->auditLogger->log('APPROVE', $record, "Approved {$record->auditLabel()} - final workflow step complete.");

        return $record;
    }

    private function stepRoleBlocksApprover(User $approver, ?string $stepRoleName): bool
    {
        if (! $stepRoleName) {
            return false;
        }

        // Only gate on roles that actually exist in this system's Role catalog -
        // an un-provisioned step role (e.g. a workflow referencing a role nobody
        // has been assigned yet) shouldn't make the workflow permanently stuck.
        $roleExists = Role::whereRaw('LOWER(name) = ?', [strtolower(trim($stepRoleName))])->exists();
        if (! $roleExists) {
            return false;
        }

        $approverRoleName = (string) $approver->tan90Profile?->role?->name;

        return strtolower(trim($approverRoleName)) !== strtolower(trim($stepRoleName));
    }

    private function openProgressFor(array $entity, Model $record): ?ApprovalProgress
    {
        return ApprovalProgress::where('entity_type', $entity['slug'])
            ->where('entity_id', $record->getKey())
            ->where('status', 'pending')
            ->latest('id')
            ->first();
    }

    /**
     * True when $dirtyFields (attribute keys about to change) intersect the
     * entity's critical_fields AND the record is already approved - i.e. this
     * edit must go through a change request rather than saving directly.
     */
    public function requiresChangeRequest(array $entity, Model $record, array $dirtyFields): bool
    {
        if (($record->approval_status ?? null) !== 'approved') {
            return false;
        }

        $critical = $entity['critical_fields'] ?? [];

        return (bool) array_intersect($critical, $dirtyFields);
    }

    public function requestCriticalChange(array $entity, Model $record, array $proposedChanges, User $requester, ?string $reason, string $priority = 'Medium'): MasterChangeRequest
    {
        return DB::transaction(function () use ($entity, $record, $proposedChanges, $requester, $reason, $priority) {
            $previousValues = collect($proposedChanges)
                ->keys()
                ->mapWithKeys(fn ($field) => [$field => $record->getAttribute($field)])
                ->all();

            $changeRequest = MasterChangeRequest::create([
                'request_no' => $this->nextRequestNumber(),
                'entity_type' => $entity['slug'],
                'entity_id' => $record->getKey(),
                'record_code' => $record->getAttribute($entity['code']) ?? $record->auditLabel(),
                'proposed_changes' => $proposedChanges,
                'previous_values' => $previousValues,
                'reason' => $reason,
                'requested_by' => $requester->id,
                'priority' => $priority,
                'approval_status' => 'pending',
            ]);

            $this->auditLogger->log(
                'SUBMIT',
                $record,
                "Requested critical change on {$record->auditLabel()}: " . implode(', ', array_keys($proposedChanges)) . '.',
                array_keys($proposedChanges),
                'Change Request'
            );

            $this->notifications->sendToApprovers('NT-MASTER-APPROVAL', [
                'record_type' => 'Change Request',
                'record_code' => $changeRequest->request_no,
                'summary' => "Change request {$changeRequest->request_no} on {$record->auditLabel()} needs approval.",
            ]);

            return $changeRequest;
        });
    }

    public function approveChangeRequest(array $entity, MasterChangeRequest $changeRequest, User $reviewer, ?string $notes = null): MasterChangeRequest
    {
        return DB::transaction(function () use ($entity, $changeRequest, $reviewer, $notes) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $entity['model'];
            $record = $modelClass::withTrashed()->findOrFail($changeRequest->entity_id);

            $record->fill($changeRequest->proposed_changes);
            $record->save(); // triggers IsMasterRecord's own UPDATE audit entry + version bump

            $nextVersion = (int) MasterChangeVersion::where('entity_type', $changeRequest->entity_type)
                ->where('entity_id', $changeRequest->entity_id)
                ->max('version_number') + 1;

            MasterChangeVersion::create([
                'tan90_master_change_request_id' => $changeRequest->id,
                'entity_type' => $changeRequest->entity_type,
                'entity_id' => $changeRequest->entity_id,
                'version_number' => $nextVersion,
                'snapshot' => $record->refresh()->toArray(),
                'created_by' => $reviewer->id,
                'effective_from' => now(),
            ]);

            $changeRequest->update([
                'approval_status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->auditLogger->log(
                'APPROVE',
                $record,
                "Approved change request {$changeRequest->request_no} on {$record->auditLabel()}.",
                array_keys($changeRequest->proposed_changes),
                'Change Request'
            );

            return $changeRequest;
        });
    }

    public function rejectChangeRequest(MasterChangeRequest $changeRequest, User $reviewer, ?string $notes = null): MasterChangeRequest
    {
        $changeRequest->update([
            'approval_status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        $this->auditLogger->log(
            'REJECT',
            null,
            trim("Rejected change request {$changeRequest->request_no}. {$notes}"),
            [],
            'Change Request'
        );

        return $changeRequest;
    }

    private function nextRequestNumber(): string
    {
        $prefix = 'CR-' . now()->format('Ym') . '-';
        $lastSequence = (int) MasterChangeRequest::where('request_no', 'like', "{$prefix}%")
            ->count();

        return $prefix . str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
    }

    private function assertHasApprovalStatus(Model $record): void
    {
        if (! array_key_exists('approval_status', $record->getAttributes())) {
            throw new RuntimeException(class_basename($record) . ' has no approval_status column.');
        }
    }
}
