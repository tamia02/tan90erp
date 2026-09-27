<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStepDecision extends Model
{
    protected $table = 'tan90_approval_step_decisions';

    protected $fillable = [
        'tan90_approval_progress_id', 'tan90_approval_workflow_step_id',
        'decided_by', 'decision', 'notes', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(ApprovalProgress::class, 'tan90_approval_progress_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflowStep::class, 'tan90_approval_workflow_step_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
