<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_order_id', 'product', 'quantity', 'list_price', 'discount', 'tax'])]
class PurchaseOrderLine extends Model
{
    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function amount(): float
    {
        return (float) ($this->quantity * $this->list_price - $this->discount + $this->tax);
    }
}
