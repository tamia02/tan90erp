<?php

namespace App\Models\Forge;

use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionPlan extends Model
{
    protected $table = 'forge_production_plans';

    protected $fillable = [
        'plan_number', 'finished_good_id', 'plant', 'target_qty', 'uom', 'due_date',
        'status', 'version', 'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'finished_good_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'production_plan_id');
    }
}
