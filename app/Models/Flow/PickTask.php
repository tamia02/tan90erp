<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickTask extends Model
{
    protected $table = 'flow_pick_tasks';

    protected $fillable = [
        'wave_id', 'allocation_id', 'qty_to_pick', 'qty_picked', 'status', 'picked_by', 'picked_at',
    ];

    protected function casts(): array
    {
        return ['picked_at' => 'datetime'];
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(PickingWave::class, 'wave_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'allocation_id');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }
}
