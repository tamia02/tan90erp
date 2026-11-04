<?php

namespace App\Models;

use App\Models\Inventory\InventoryItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_order_id', 'product', 'hsn_sac', 'quantity', 'uom', 'list_price', 'discount', 'tax', 'gst_rate', 'custom_fields'])]
class PurchaseOrderLine extends Model
{
    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'custom_fields' => 'array',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function documentProduct(): string
    {
        $item = InventoryItem::where('sku', $this->product)->orWhere('code', $this->product)->first();

        return $item?->documentName() ?: (string) $this->product;
    }

    public function amount(): float
    {
        return (float) ($this->quantity * $this->list_price - $this->discount + $this->tax);
    }
}
