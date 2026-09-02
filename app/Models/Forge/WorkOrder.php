<?php

namespace App\Models\Forge;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\Routing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkOrder extends Model
{
    // Draft -> Released -> MaterialReserved -> MaterialIssued -> InProgress
    // -> Reconciliation -> FinalQcPending -> ReleasedToFg/Rework/Rejected -> Closed
    public const STATUSES = [
        'draft', 'released', 'material_reserved', 'material_issued', 'in_progress',
        'reconciliation', 'final_qc_pending', 'released_to_fg', 'rework', 'rejected', 'closed',
    ];

    protected $table = 'forge_work_orders';

    protected $fillable = [
        'wo_number', 'production_plan_id', 'source_deviation_id', 'finished_good_id', 'bom_id', 'recipe_id', 'routing_id',
        'plant', 'batch_number', 'target_qty', 'good_qty', 'rework_qty', 'rejected_qty', 'uom',
        'status', 'created_by', 'released_by', 'released_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function productionPlan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function sourceDeviation(): BelongsTo
    {
        return $this->belongsTo(Deviation::class, 'source_deviation_id');
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'finished_good_id');
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class, 'routing_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function jobCards(): HasMany
    {
        return $this->hasMany(JobCard::class, 'work_order_id')->orderBy('sequence');
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class, 'work_order_id');
    }

    public function productionEntries(): HasMany
    {
        return $this->hasMany(ProductionEntry::class, 'work_order_id');
    }

    public function wastageRecords(): HasMany
    {
        return $this->hasMany(WastageRecord::class, 'work_order_id');
    }

    public function qualityHolds(): HasMany
    {
        return $this->hasMany(QualityHold::class, 'work_order_id');
    }

    public function finalQcResult(): HasOne
    {
        return $this->hasOne(FinalQcResult::class, 'work_order_id')->latestOfMany();
    }

    public function deviations(): HasMany
    {
        return $this->hasMany(Deviation::class, 'work_order_id');
    }

    public function batch(): HasOne
    {
        return $this->hasOne(Batch::class, 'work_order_id');
    }

    public function downtimeEvents(): HasMany
    {
        return $this->hasMany(MachineDowntimeEvent::class, 'work_order_id');
    }

    public function hasOpenHold(): bool
    {
        return $this->qualityHolds()->where('status', 'open')->exists();
    }
}
