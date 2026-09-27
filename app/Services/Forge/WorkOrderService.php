<?php

namespace App\Services\Forge;

use App\Models\Forge\Batch;
use App\Models\Forge\FinalQcResult;
use App\Models\Forge\JobCard;
use App\Models\Forge\MaterialIssue;
use App\Models\Forge\ProductionEntry;
use App\Models\Forge\WorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Owns every Work Order state transition. Transitions are commands, not
// editable status fields (ARCHITECTURE.md #5) - every method here checks
// the current state before moving, so a status can never be hand-edited
// into an invalid position by a form. Only Blueprint's released BOM/Recipe/
// Routing can be snapshotted onto a work order; Forge never writes back to
// those tables.
class WorkOrderService
{
    public function release(WorkOrder $wo, User $actor): WorkOrder
    {
        $this->assertStatus($wo, ['draft']);

        if ($wo->bom_id === null && $wo->recipe_id === null) {
            throw ValidationException::withMessages(['status' => 'Work order needs a released BOM or Recipe before it can be released.']);
        }

        $wo->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);
        AuditLogger::log('Work order released', $wo->wo_number, $wo);

        return $wo;
    }

    public function reserveMaterial(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['released']);
        $wo->update(['status' => 'material_reserved']);
        AuditLogger::log('Work order material reserved', $wo->wo_number, $wo);

        return $wo;
    }

    public function issueMaterial(WorkOrder $wo, array $lines, User $actor): WorkOrder
    {
        $this->assertStatus($wo, ['material_reserved']);

        DB::transaction(function () use ($wo, $lines, $actor) {
            foreach ($lines as $line) {
                MaterialIssue::create([
                    'work_order_id' => $wo->id,
                    'item_code' => $line['item_code'],
                    'item_name' => $line['item_name'],
                    'lot_number' => $line['lot_number'] ?? null,
                    'qty' => $line['qty'],
                    'uom' => $line['uom'],
                    'movement_type' => 'issue',
                    'posted_by' => $actor->id,
                    'posted_at' => now(),
                ]);
            }
            $wo->update(['status' => 'material_issued']);
        });

        AuditLogger::log('Material issued to work order', $wo->wo_number, $wo);

        return $wo;
    }

    public function startProgress(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['material_issued']);
        $wo->update(['status' => 'in_progress']);
        AuditLogger::log('Work order started', $wo->wo_number, $wo);

        return $wo;
    }

    public function recordProduction(WorkOrder $wo, array $data, User $actor): ProductionEntry
    {
        $this->assertStatus($wo, ['in_progress', 'reconciliation']);

        if ($wo->hasOpenHold()) {
            throw ValidationException::withMessages(['status' => 'Work order has an open quality hold - resolve it before recording production.']);
        }

        $entry = ProductionEntry::create([
            'work_order_id' => $wo->id,
            'job_card_id' => $data['job_card_id'] ?? null,
            'good_qty' => $data['good_qty'],
            'rework_qty' => $data['rework_qty'] ?? 0,
            'rejected_qty' => $data['rejected_qty'] ?? 0,
            'uom' => $wo->uom,
            'status' => 'draft',
            'recorded_by' => $actor->id,
        ]);

        if ($wo->status === 'in_progress') {
            $wo->update(['status' => 'reconciliation']);
        }

        AuditLogger::log('Production entry recorded', $wo->wo_number, $entry);

        return $entry;
    }

    public function approveProduction(ProductionEntry $entry, User $actor): ProductionEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft entries can be approved.']);
        }

        DB::transaction(function () use ($entry, $actor) {
            $entry->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $wo = $entry->workOrder;
            $wo->increment('good_qty', $entry->good_qty);
            $wo->increment('rework_qty', $entry->rework_qty);
            $wo->increment('rejected_qty', $entry->rejected_qty);
        });

        AuditLogger::log('Production entry approved', $entry->workOrder->wo_number, $entry);

        return $entry;
    }

    public function sendToFinalQc(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['reconciliation']);

        if ((float) $wo->good_qty <= 0) {
            throw ValidationException::withMessages(['status' => 'No reconciled good quantity to send for final QC.']);
        }

        $wo->update(['status' => 'final_qc_pending']);
        AuditLogger::log('Work order sent to final QC', $wo->wo_number, $wo);

        return $wo;
    }

    public function recordFinalQc(WorkOrder $wo, array $data, User $actor): FinalQcResult
    {
        $this->assertStatus($wo, ['final_qc_pending']);

        $result = FinalQcResult::create([
            'work_order_id' => $wo->id,
            'accepted_qty' => $data['accepted_qty'],
            'rejected_qty' => $data['rejected_qty'] ?? 0,
            'rework_qty' => $data['rework_qty'] ?? 0,
            'specification_results' => $data['specification_results'] ?? null,
            'result' => $data['result'],
            'inspected_by' => $actor->id,
        ]);

        AuditLogger::log('Final QC recorded', $wo->wo_number, $result);

        return $result;
    }

    public function releaseFinalQc(FinalQcResult $result, User $actor): WorkOrder
    {
        $wo = $result->workOrder;
        $this->assertStatus($wo, ['final_qc_pending']);

        $statusMap = ['released' => 'released_to_fg', 'rework' => 'rework', 'rejected' => 'rejected'];
        $newStatus = $statusMap[$result->result] ?? 'rejected';

        DB::transaction(function () use ($wo, $result, $actor, $newStatus) {
            $result->update(['released_by' => $actor->id, 'released_at' => now()]);
            $wo->update(['status' => $newStatus]);

            if ($newStatus === 'released_to_fg') {
                Batch::create([
                    'work_order_id' => $wo->id,
                    'batch_number' => $wo->batch_number ?: $wo->wo_number,
                    'qty' => $result->accepted_qty,
                    'uom' => $wo->uom,
                    'status' => 'released',
                    'released_at' => now(),
                ]);
            }
        });

        AuditLogger::log('Final QC released - '.$newStatus, $wo->wo_number, $wo);

        return $wo;
    }

    public function close(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['released_to_fg', 'rejected']);
        $wo->update(['status' => 'closed', 'closed_at' => now()]);
        AuditLogger::log('Work order closed', $wo->wo_number, $wo);

        return $wo;
    }

    public function startJobCard(JobCard $card): JobCard
    {
        if ($card->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a pending job card can be started.']);
        }

        if ($card->precedingCardsIncomplete()) {
            throw ValidationException::withMessages(['status' => 'A preceding operation is not yet completed.']);
        }

        if ($card->workOrder->hasOpenHold()) {
            throw ValidationException::withMessages(['status' => 'Work order has an open quality hold.']);
        }

        $card->update(['status' => 'started', 'started_at' => now()]);
        AuditLogger::log('Job card started', $card->operation_name, $card);

        return $card;
    }

    public function pauseJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['started']);
        $card->update(['status' => 'paused', 'paused_at' => now()]);
        AuditLogger::log('Job card paused', $card->operation_name, $card);

        return $card;
    }

    public function resumeJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['paused']);
        $card->update(['status' => 'started', 'paused_at' => null]);
        AuditLogger::log('Job card resumed', $card->operation_name, $card);

        return $card;
    }

    public function completeJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['started', 'paused']);
        $card->update(['status' => 'completed', 'completed_at' => now()]);
        AuditLogger::log('Job card completed', $card->operation_name, $card);

        return $card;
    }

    private function assertStatus(WorkOrder $wo, array $allowed): void
    {
        if (! in_array($wo->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Work order {$wo->wo_number} is '{$wo->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }

    private function assertJobCardStatus(JobCard $card, array $allowed): void
    {
        if (! in_array($card->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Job card is '{$card->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }
}
