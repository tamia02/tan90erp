<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'created_by', 'gate_entry_id', 'sku', 'po_qty', 'invoice_qty', 'physical_received',
    'accepted_qty', 'qc_hold_qty', 'defective_qty', 'rejected_qty', 'missing_qty', 'qc_reasons',
    'suggested_bin', 'posted',
    'vendor_response', 'vendor_response_note', 'vendor_cn_dn_path', 'vendor_responded_at', 'vendor_responded_by',
    'vendor_notified_at', 'vendor_notified_by',
])]
class GrnRecord extends Model
{
    protected function casts(): array
    {
        return [
            'posted' => 'boolean',
            'vendor_responded_at' => 'datetime',
            'vendor_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GrnRecordLine::class);
    }
}
