<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalWorkflowStep extends Model
{
    use IsConfigRecord;

    protected $table = 'tan90_approval_workflow_steps';

    protected $fillable = ['code', 'tan90_approval_workflow_id', 'step_order', 'step_role', 'sla_hours', 'status'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'tan90_approval_workflow_id');
    }
}
