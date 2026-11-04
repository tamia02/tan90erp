<?php

namespace App\Models\Forge;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalQcResult extends Model
{
    protected $table = 'forge_final_qc_results';

    protected $fillable = [
        'work_order_id', 'accepted_qty', 'rejected_qty', 'rework_qty', 'specification_results',
        'result', 'inspected_by', 'released_by', 'released_at', 'leak_test', 'pressure_test', 'physical_check', 'recovered_pcm_qty',
        'started_at', 'ended_at', 'manpower', 'cycle_no', 'cycle_accepted_qty', 'cycle_rejected_qty', 'cycle_rework_qty',
    ];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
