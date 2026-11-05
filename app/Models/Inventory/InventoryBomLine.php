<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBomLine extends Model
{
    protected $fillable = ['fg_item_id', 'component_item_id', 'qty_per_unit'];

    protected function casts(): array
    {
        return ['qty_per_unit' => 'decimal:4'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'component_item_id');
    }
}
