<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomLine extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_bom_lines';

    protected $fillable = [
        'tan90_bom_version_id', 'line_type', 'tan90_component_id', 'tan90_sub_bom_id',
        'sequence', 'quantity', 'uom', 'wastage_percent', 'is_alternate',
    ];

    protected function casts(): array
    {
        return ['is_alternate' => 'boolean'];
    }

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'tan90_bom_version_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_component_id');
    }

    /** Nested/packaging BOM reference; CircularReferenceService walks this chain. */
    public function subBom(): BelongsTo
    {
        return $this->belongsTo(Bom::class, 'tan90_sub_bom_id');
    }
}
