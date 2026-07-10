<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'gate_entry_id', 'sku', 'po_qty', 'invoice_qty', 'physical_received',
    'accepted_qty', 'qc_hold_qty', 'defective_qty', 'rejected_qty', 'missing_qty', 'qc_reasons',
    'suggested_bin', 'posted',
])]
class GrnRecord extends Model
{
    protected function casts(): array
    {
        return [
            'posted' => 'boolean',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
