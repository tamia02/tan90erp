<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'created_by', 'gate_entry_id', 'sku', 'po_qty', 'invoice_qty', 'physical_received', 'invoice_amount', 'unit_price',
    'accepted_qty', 'qc_hold_qty', 'defective_qty', 'rejected_qty', 'missing_qty', 'qc_reasons', 'parameters', 'documents_checked',
    'hold_reason', 'hold_document_path', 'evidence_images', 'evidence_image_type',
    'return_status', 'return_requested_at', 'return_initiated_at',
])]
class QcResult extends Model
{
    protected function casts(): array
    {
        return [
            'return_requested_at' => 'datetime',
            'return_initiated_at' => 'datetime',
            'parameters' => 'array',
            'documents_checked' => 'array',
            'invoice_amount' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'evidence_images' => 'array',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return HasMany<QcEvidenceImage, $this> */
    public function evidenceImageRecords(): HasMany
    {
        return $this->hasMany(QcEvidenceImage::class);
    }

    /**
     * Multi-SKU receipts keep one independently auditable QC split per SKU.
     * The parent row remains as an aggregate for older screens/integrations.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QcResultLine::class);
    }
}
