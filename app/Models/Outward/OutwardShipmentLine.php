<?php

namespace App\Models\Outward;

use App\Models\Inventory\InventoryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutwardShipmentLine extends Model
{
    protected $fillable = ['outward_shipment_id', 'item_id', 'qty', 'rate', 'value', 'dispatch_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2', 'value' => 'decimal:2'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(OutwardShipment::class, 'outward_shipment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
