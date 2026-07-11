<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'gate_no', 'entry_type', 'po_number', 'po_bill_date', 'vendor_name', 'vendor_gst',
    'invoice_number', 'invoice_qty', 'invoice_amount', 'rate', 'material',
    'vehicle_number', 'driver_name', 'transporter', 'location', 'gps',
    'bill_scanned', 'remarks', 'status', 'sla_deadline', 'loading_dock', 'dock_assigned_at',
])]
class GateEntry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'invoice_amount' => 'decimal:2',
            'po_bill_date' => 'date',
            'bill_scanned' => 'boolean',
            'sla_deadline' => 'datetime',
            'dock_assigned_at' => 'datetime',
        ];
    }

    /** @return HasMany<ValidationIssue, $this> */
    public function validationIssues(): HasMany
    {
        return $this->hasMany(ValidationIssue::class);
    }

    /** @return HasOne<UnloadingRecord, $this> */
    public function unloadingRecord(): HasOne
    {
        return $this->hasOne(UnloadingRecord::class);
    }

    /** @return HasOne<QcResult, $this> */
    public function qcResult(): HasOne
    {
        return $this->hasOne(QcResult::class);
    }

    /** @return HasOne<GrnRecord, $this> */
    public function grnRecord(): HasOne
    {
        return $this->hasOne(GrnRecord::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasOne<FinanceRecord, $this> */
    public function financeRecord(): HasOne
    {
        return $this->hasOne(FinanceRecord::class);
    }

    /**
     * A gate entry is blocked from auto-clearing while it has an open
     * hard-fail or red-flag issue — mirrors ADD_GATE_ENTRY/UPDATE_ISSUE's
     * blocking logic in the React prototype's store.tsx.
     */
    public function hasBlockingOpenIssues(): bool
    {
        return $this->validationIssues()
            ->where('status', 'open')
            ->whereIn('severity', ['hardFail', 'redFlag'])
            ->exists();
    }
}
