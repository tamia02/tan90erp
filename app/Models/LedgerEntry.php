<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['gate_entry_id', 'sku', 'bin', 'bucket', 'qty', 'unit_price', 'extended_value'])]
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'extended_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
