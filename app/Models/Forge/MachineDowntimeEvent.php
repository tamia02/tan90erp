<?php

namespace App\Models\Forge;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineDowntimeEvent extends Model
{
    protected $table = 'forge_machine_downtime_events';

    protected $fillable = [
        'machine_id', 'work_order_id', 'category', 'severity', 'observation',
        'owner_user_id', 'started_at', 'ended_at', 'root_cause', 'corrective_action',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function durationMinutes(): ?int
    {
        if (! $this->ended_at) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }
}
