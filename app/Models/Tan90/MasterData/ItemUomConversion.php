<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUomConversion extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_item_uom_conversions';

    protected $fillable = ['code', 'tan90_item_id', 'tan90_uom_id', 'conversion_factor', 'status', 'approval_status'];

    public static function criticalFields(): array
    {
        return ['conversion_factor'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'tan90_item_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'tan90_uom_id');
    }
}
