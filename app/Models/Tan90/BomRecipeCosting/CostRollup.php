<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostRollup extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_cost_rollups';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'tan90_bom_version_id', 'cost_period', 'input_hash',
        'material_cost', 'labor_cost', 'machine_cost', 'utility_cost', 'overhead_cost',
        'total_cost', 'status', 'rolled_up_at', 'rolled_up_by',
    ];

    protected function casts(): array
    {
        return ['rolled_up_at' => 'datetime'];
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'tan90_bom_version_id');
    }

    public function rolledUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_up_by');
    }
}
