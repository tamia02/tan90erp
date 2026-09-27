<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoProduct extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_co_products';

    protected $fillable = [
        'tan90_bom_version_id', 'name', 'quantity', 'uom', 'value_allocation_percent',
    ];

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'tan90_bom_version_id');
    }
}
