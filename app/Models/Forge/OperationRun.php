<?php

namespace App\Models\Forge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationRun extends Model
{
    protected $table = 'forge_operation_runs';
    protected $fillable = ['work_order_id', 'operation', 'cycle_no', 'context_id', 'status', 'started_at', 'ended_at', 'started_by', 'completed_by', 'payload'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'payload' => 'array'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class,'started_by');
    }
}
