<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'po_number', 'vendor_name', 'invoice_number', 'invoice_qty', 'material',
    'has_invoice', 'has_eway_bill', 'has_lr_pod', 'status', 'note',
    'expected_arrival_at', 'vehicle_number', 'dock_number', 'dock_scheduled_at',
])]
class VendorSubmission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'has_invoice' => 'boolean',
            'has_eway_bill' => 'boolean',
            'has_lr_pod' => 'boolean',
            'expected_arrival_at' => 'datetime',
            'dock_scheduled_at' => 'datetime',
        ];
    }
}
