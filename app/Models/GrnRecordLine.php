<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'grn_record_id', 'gate_entry_id', 'sku', 'po_qty', 'invoice_qty', 'physical_received',
    'accepted_qty', 'qc_hold_qty', 'defective_qty', 'rejected_qty', 'missing_qty',
    'qc_reasons', 'suggested_bin',
    'unit_price', 'invoice_amount', 'accepted_value',
])]
class GrnRecordLine extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'invoice_amount' => 'decimal:2',
            'accepted_value' => 'decimal:2',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(GrnRecord::class, 'grn_record_id');
    }

    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
