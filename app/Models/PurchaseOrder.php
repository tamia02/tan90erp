<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'po_number', 'po_owner', 'subject', 'requisition_number', 'vendor_name', 'contact_name',
    'po_date', 'due_date', 'status', 'carrier', 'tracking_number', 'excise_duty', 'sales_commission',
    'billing_country', 'billing_building', 'billing_street', 'billing_city', 'billing_state', 'billing_zip',
    'shipping_country', 'shipping_building', 'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip',
    'discount', 'tax', 'adjustment', 'terms_and_conditions', 'description',
])]
class PurchaseOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'due_date' => 'date',
            'excise_duty' => 'decimal:2',
            'sales_commission' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'adjustment' => 'decimal:2',
        ];
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** The primary line item — every PO seen so far is single-line-item. */
    public function primaryLine(): ?PurchaseOrderLine
    {
        return $this->lines->first();
    }

    public function subTotal(): float
    {
        return (float) $this->lines->sum(
            fn (PurchaseOrderLine $line) => $line->quantity * $line->list_price - $line->discount + $line->tax,
        );
    }

    public function grandTotal(): float
    {
        return $this->subTotal() - (float) ($this->discount ?? 0) + (float) ($this->tax ?? 0) + (float) ($this->adjustment ?? 0);
    }
}
