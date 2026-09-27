<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bom extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_boms';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'bom_type', 'status', 'approval_status',
    ];

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BomVersion::class, 'tan90_bom_id')->orderByDesc('revision_number');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(BomVersion::class, 'tan90_bom_id')->where('is_current', true);
    }

    /** Where a BOM is used as a sub-BOM inside another BOM's lines (used by WhereUsedService). */
    public function usedInLines(): HasMany
    {
        return $this->hasMany(BomLine::class, 'tan90_sub_bom_id');
    }
}
