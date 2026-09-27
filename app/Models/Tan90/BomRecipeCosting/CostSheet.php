<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostSheet extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_cost_sheets';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'cost_period', 'material_cost', 'labor_cost',
        'machine_cost', 'utility_cost', 'overhead_cost', 'landed_cost',
        'total_standard_cost', 'total_actual_cost', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['total_standard_cost'];
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function variances(): HasMany
    {
        return $this->hasMany(CostVariance::class, 'tan90_cost_sheet_id');
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(CostSimulation::class, 'tan90_cost_sheet_id');
    }
}
