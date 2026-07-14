<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostSimulation extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_cost_simulations';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'tan90_cost_sheet_id', 'scenario_name',
        'adjustments', 'simulated_total_cost', 'margin_percent', 'created_by',
    ];

    protected function casts(): array
    {
        return ['adjustments' => 'array'];
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function costSheet(): BelongsTo
    {
        return $this->belongsTo(CostSheet::class, 'tan90_cost_sheet_id');
    }
}
