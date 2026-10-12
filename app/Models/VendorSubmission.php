<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'po_number', 'vendor_name', 'invoice_number', 'invoice_date', 'invoice_due_date', 'invoice_qty', 'invoice_amount', 'material', 'line_quantities',
    'has_invoice', 'invoice_document_path', 'has_eway_bill', 'eway_bill_document_path',
    'has_lr_pod', 'lr_pod_document_path', 'status', 'note',
    'expected_arrival_at', 'vehicle_number', 'dock_number', 'dock_scheduled_at',
    'production_started_at', 'dispatched_at', 'tat_due_at',
])]
class VendorSubmission extends Model
{
    use \App\Models\Concerns\SiteScopedViaParent;

    // Site = its PO's site.
    public static function siteParent(): array
    {
        return ['po_number', PurchaseOrder::class, 'po_number'];
    }

    use HasFactory;

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'invoice_due_date' => 'date',
            'invoice_amount' => 'decimal:2',
            'has_invoice' => 'boolean',
            'has_eway_bill' => 'boolean',
            'has_lr_pod' => 'boolean',
            'expected_arrival_at' => 'datetime',
            'dock_scheduled_at' => 'datetime',
            'production_started_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'tat_due_at' => 'datetime',
            'line_quantities' => 'array',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_number', 'po_number');
    }
}
