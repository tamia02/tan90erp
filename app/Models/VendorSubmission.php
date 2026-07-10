<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'po_number', 'vendor_name', 'invoice_number', 'invoice_qty', 'material',
    'has_invoice', 'has_eway_bill', 'has_lr_pod', 'status', 'note',
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
        ];
    }
}
