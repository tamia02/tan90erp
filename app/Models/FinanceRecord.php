<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'created_by', 'gate_entry_id', 'vendor_name', 'invoice_number', 'rate_per_unit',
    'invoice_value', 'accepted_value', 'deduction_defective', 'deduction_rejected',
    'deduction_missing', 'final_payable', 'vendor_status', 'notes',
])]
class FinanceRecord extends Model
{
    protected function casts(): array
    {
        return [
            'rate_per_unit' => 'decimal:2',
            'invoice_value' => 'decimal:2',
            'accepted_value' => 'decimal:2',
            'deduction_defective' => 'decimal:2',
            'deduction_rejected' => 'decimal:2',
            'deduction_missing' => 'decimal:2',
            'final_payable' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
