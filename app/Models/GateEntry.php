<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'created_by', 'approved_by', 'approved_at', 'store_exec_verified_by', 'store_exec_verified_at', 'visitor_host_id', 'gate_no', 'entry_type', 'po_number', 'po_bill_date', 'vendor_name', 'vendor_gst',
    'invoice_number', 'invoice_qty', 'invoice_amount', 'rate', 'material',
    'product_lines',
    'vehicle_number', 'driver_name', 'transporter', 'location', 'gps',
    'bill_scanned', 'bill_document_path', 'remarks', 'status', 'sla_deadline', 'loading_dock', 'dock_assigned_at',
    'loading_window_start', 'loading_window_end', 'expected_delivery_date', 'loading_started_at', 'dispatch_documents_shared', 'loaded_at', 'exited_at',
    'putaway_by', 'putaway_completed_at', 'final_bin', 'visitor_checked_in_at', 'visitor_checked_out_at',
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
            'loading_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'store_exec_verified_at' => 'datetime',
            'loading_window_start' => 'datetime',
            'loading_window_end' => 'datetime',
            'expected_delivery_date' => 'date',
            'dispatch_documents_shared' => 'boolean',
            'loaded_at' => 'datetime',
            'exited_at' => 'datetime',
            'putaway_completed_at' => 'datetime',
            'product_lines' => 'array',
            'visitor_checked_in_at' => 'datetime',
            'visitor_checked_out_at' => 'datetime',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function storeExecVerifiedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'store_exec_verified_by');
    }

    public function purchaseOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_number', 'po_number');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function putawayBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'putaway_by');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function visitorHost(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_host_id');
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

    /**
     * Guard-facing lists must not disclose the complete PO reference. Keep
     * the full value available to internal workflows, but expose only the
     * final identifier digit when a guard is reviewing past entries.
     */
    public function maskedPoNumber(): string
    {
        $reference = preg_replace('/\s+/', '', trim((string) $this->po_number));
        if ($reference === '') {
            return '—';
        }

        preg_match_all('/\d/', $reference, $matches);
        $lastVisible = $matches[0] ? end($matches[0]) : substr($reference, -1);

        return str_repeat('*', max(strlen($reference) - 1, 1)).$lastVisible;
    }
}
