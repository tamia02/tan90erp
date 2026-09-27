<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostVariance extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_cost_variances';

    protected $fillable = [
        'tan90_cost_sheet_id', 'variance_type', 'standard_cost', 'actual_cost',
        'variance_amount', 'variance_percent', 'reason',
    ];

    public function costSheet(): BelongsTo
    {
        return $this->belongsTo(CostSheet::class, 'tan90_cost_sheet_id');
    }
}
