<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'po_number', 'po_owner', 'subject', 'requisition_number', 'vendor_name', 'contact_name',
    'po_date', 'due_date', 'status', 'submitted_at', 'released_at', 'carrier', 'tracking_number', 'excise_duty', 'sales_commission',
    'billing_country', 'billing_building', 'billing_street', 'billing_city', 'billing_state', 'billing_zip',
    'shipping_country', 'shipping_building', 'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip',
    'discount', 'tax', 'adjustment', 'terms_and_conditions', 'description', 'reference_number', 'payment_terms', 'valid_upto', 'purchase_nature', 'material_type', 'terms_template_id', 'shipment_preference', 'reverse_charge', 'tax_level', 'warehouse_location', 'header_custom_fields', 'zoho_custom_fields', 'attachments', 'pdf_template',
])]
class PurchaseOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'due_date' => 'date',
            'valid_upto' => 'date',
            'released_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reverse_charge' => 'boolean',
            'excise_duty' => 'decimal:2',
            'sales_commission' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'adjustment' => 'decimal:2',
            'header_custom_fields' => 'array',
            'zoho_custom_fields' => 'array',
            'attachments' => 'array',
        ];
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * Operational modules use the PO number printed on supplier paperwork as
     * their shared business key. These relationships keep that existing
     * contract explicit and let the control tower traverse the whole flow.
     */
    public function vendorSubmissions(): HasMany
    {
        return $this->hasMany(VendorSubmission::class, 'po_number', 'po_number');
    }

    public function gateEntries(): HasMany
    {
        return $this->hasMany(GateEntry::class, 'po_number', 'po_number');
    }

    public function acknowledgement(): HasOne
    {
        return $this->hasOne(PurchaseOrderAcknowledgement::class, 'po_number', 'po_number');
    }

    /** The primary line item — every PO seen so far is single-line-item. */
    public function primaryLine(): ?PurchaseOrderLine
    {
        return $this->lines->first();
    }

    public function subTotal(): float
    {
        return (float) $this->lines->sum(
            fn (PurchaseOrderLine $line) => $line->quantity * $line->list_price - $line->discount,
        );
    }

    public function taxTotal(): float
    {
        if ($this->reverse_charge) {
            return 0;
        }

        return (float) $this->lines->sum(fn (PurchaseOrderLine $line) => $line->tax)
            + (float) ($this->tax ?? 0);
    }

    public function grandTotal(): float
    {
        return $this->subTotal() - (float) ($this->discount ?? 0) + $this->taxTotal() + (float) ($this->adjustment ?? 0);
    }
}
