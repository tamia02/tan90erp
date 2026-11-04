<?php

namespace App\Models\Outward;

use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\PackagingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutwardShipmentLine extends Model
{
    protected $fillable = ['outward_shipment_id', 'item_id', 'warehouse_id', 'packaging_type_id', 'packaging_quantity', 'box_size', 'qty', 'rate', 'value', 'gst_rate', 'cgst_rate', 'sgst_rate', 'igst_rate', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'total_amount', 'tax_type', 'dispatch_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2', 'value' => 'decimal:2', 'packaging_quantity' => 'integer', 'gst_rate' => 'decimal:3', 'cgst_rate' => 'decimal:3', 'sgst_rate' => 'decimal:3', 'igst_rate' => 'decimal:3', 'cgst_amount' => 'decimal:2', 'sgst_amount' => 'decimal:2', 'igst_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(OutwardShipment::class, 'outward_shipment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class, 'packaging_type_id');
    }
}
