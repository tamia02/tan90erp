<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'qc_result_id', 'gate_entry_id', 'sku', 'po_qty', 'invoice_qty', 'physical_received',
    'accepted_qty', 'qc_hold_qty', 'defective_qty', 'rejected_qty', 'missing_qty',
    'invoice_amount', 'unit_price', 'qc_reasons', 'hold_reason', 'hold_document_path', 'suggested_bin',
    'parameters', 'documents_checked', 'evidence_images',
])]
class QcResultLine extends Model
{
    protected function casts(): array
    {
        return [
            'invoice_amount' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'parameters' => 'array',
            'documents_checked' => 'array',
            'evidence_images' => 'array',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(QcResult::class, 'qc_result_id');
    }

    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
