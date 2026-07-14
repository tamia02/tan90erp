<?php

namespace App\Models\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per submission of a master record. Written by
 * ApprovalService::submit() for *every* entity (workflow or not) so
 * CheckSlaBreaches has a submitted_at to measure against; tan90_approval_workflow_id
 * and current_step_order are only set when the entity's module matches an
 * active multi-step tan90_approval_workflow.
 */
class ApprovalProgress extends Model
{
    protected $table = 'tan90_approval_progress';

    protected $fillable = [
        'entity_type', 'entity_id', 'tan90_approval_workflow_id', 'current_step_order',
        'status', 'submitted_at', 'sla_warned_at', 'sla_escalated_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'sla_warned_at' => 'datetime',
        'sla_escalated_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'tan90_approval_workflow_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalStepDecision::class, 'tan90_approval_progress_id');
    }

    public function currentStep(): ?ApprovalWorkflowStep
    {
        if (! $this->tan90_approval_workflow_id || ! $this->current_step_order) {
            return null;
        }

        return ApprovalWorkflowStep::where('tan90_approval_workflow_id', $this->tan90_approval_workflow_id)
            ->where('step_order', $this->current_step_order)
            ->first();
    }
}
