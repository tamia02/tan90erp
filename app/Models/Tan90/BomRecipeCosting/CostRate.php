<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostRate extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_cost_rates';

    protected $fillable = [
        'code', 'rate_type', 'rate_name', 'reference_id', 'rate', 'uom',
        'effective_from', 'effective_to', 'status', 'approval_status',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    /** Effective on a given date, per rate_type — used by CostRollupService. */
    public function scopeEffectiveOn($query, string $rateType, ?string $date = null)
    {
        $date ??= now()->toDateString();

        return $query->where('rate_type', $rateType)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
