<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_items';

    protected $fillable = [
        'sku', 'code', 'name', 'tan90_item_category_id', 'tan90_uom_id', 'hsn',
        'masking_code', 'qc_required', 'batch_tracking', 'temperature', 'storage',
        'reorder_level', 'standard_cost', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['tan90_uom_id', 'hsn', 'standard_cost'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'tan90_item_category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'tan90_uom_id');
    }
}
