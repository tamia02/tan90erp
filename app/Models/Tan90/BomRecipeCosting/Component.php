<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Component extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_components';

    protected $fillable = [
        'code', 'name', 'masking_code', 'type', 'uom', 'standard_cost', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['standard_cost', 'uom'];
    }

    // The real chemical name (and the old "code" abbreviation, e.g.
    // "CMP-MGNO3" for Magnesium Nitrate) must never appear in audit text -
    // only the opaque masking_code identifies this record everywhere.
    public function auditLabel(): string
    {
        return $this->getAttribute('masking_code')
            ?: class_basename($this).' #'.$this->getKey();
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'tan90_component_id');
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(BomLine::class, 'tan90_component_id');
    }
}
