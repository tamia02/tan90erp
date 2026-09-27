<?php

namespace App\Models\Forge;

use App\Models\Tan90\BomRecipeCosting\RoutingOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCard extends Model
{
    protected $table = 'forge_job_cards';

    protected $fillable = [
        'work_order_id', 'routing_operation_id', 'sequence', 'operation_name', 'machine_id',
        'operator_user_id', 'planned_qty', 'status', 'started_at', 'paused_at', 'completed_at',
        'process_parameters_json',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'process_parameters_json' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class, 'routing_operation_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function qualityHolds(): HasMany
    {
        return $this->hasMany(QualityHold::class, 'job_card_id');
    }

    public function precedingCardsIncomplete(): bool
    {
        return $this->workOrder->jobCards()
            ->where('sequence', '<', $this->sequence)
            ->where('status', '!=', 'completed')
            ->exists();
    }
}
