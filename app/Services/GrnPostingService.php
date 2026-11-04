<?php

namespace App\Services;

use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrder;
use App\Services\Inventory\WarehouseStockResolver;
use App\Support\SiteLocation;
use Illuminate\Validation\ValidationException;

// Ports the React prototype's SAVE_GRN reducer case — reads the gate's
// QcResult (produced separately by QC Check) and is the ONLY thing that
// posts to the stock ledger and creates the finance payable record. This
// separation (QC splits, GRN posts) is the client's explicit "don't merge
// modules" requirement.
class GrnPostingService
{
    public function __construct(private readonly ThreeWayMatchService $threeWayMatch) {}

    /**
     * Previously a flat ₹42/unit for every vendor regardless of what the PO
     * or the gate's own invoice actually said — ThreeWayMatchService already
     * correctly compares gate->rate against the PO's list_price and flags a
     * mismatch, but GrnPostingService threw that real rate away and posted
     * the ledger/finance numbers (invoice value, deductions, final payable,
     * debit notes) at the hardcoded placeholder anyway. Wrong for every
     * vendor except one priced at exactly ₹42/unit. Now uses the rate the
     * guard captured (and the 3-way match already validated against the PO),
     * falling back to the PO's own list price if the gate's rate is unset.
     */
    private function resolveLineRate(GateEntry $gate, string $sku, mixed $recordedRate, ?PurchaseOrder $po): float
    {
        if ($recordedRate !== null && is_numeric($recordedRate)) {
            return (float) $recordedRate;
        }

        $line = $po?->lines->first(fn ($candidate) => mb_strtolower(trim((string) $candidate->product)) === mb_strtolower(trim($sku)));
        if ($line) {
            return (float) $line->list_price;
        }

        if ($po?->lines->count() === 1) {
            return (float) $po->lines->first()->list_price;
        }

        return (float) ($gate->rate ?? 0);
    }

    public function post(GateEntry $gate, string|array $suggestedBin): ?GrnRecord
    {
        $gate->loadMissing(['qcResult.lines', 'grnRecord.lines']);
        $qc = $gate->qcResult;
        if (! $qc) {
            return null;
        }

        if ($gate->grnRecord?->posted) {
            return $gate->grnRecord;
        }

        if ((int) $qc->qc_hold_qty > 0) {
            throw ValidationException::withMessages([
                'grn' => "Resolve the QC hold of {$qc->qc_hold_qty} units before posting GRN. Vendor GRN reports only final accepted/rejected quantities.",
            ]);
        }

        // A QC-held receipt may already have been physically put away. In
        // that case keep the Store Executive's confirmed bin as the GRN bin
        // and close the gate as soon as the final GRN is posted.
        $putawayAlreadyComplete = filled($gate->putaway_completed_at) && filled($gate->final_bin);
        $purchaseOrder = $gate->po_number
            ? PurchaseOrder::where('po_number', $gate->po_number)->with('lines')->first()
            : null;

        // New multi-SKU QC records have one child row per SKU. Older records
        // are represented by a synthetic line so the posting path remains
        // backward compatible without ever merging ledger SKUs.
        $qcLines = $qc->lines->isNotEmpty()
            ? $qc->lines->map(fn ($line) => [
                'sku' => $line->sku,
                'po_qty' => (int) $line->po_qty,
                'invoice_qty' => (int) $line->invoice_qty,
                'physical_received' => (int) $line->physical_received,
                'accepted_qty' => (int) $line->accepted_qty,
                'qc_hold_qty' => (int) $line->qc_hold_qty,
                'defective_qty' => (int) $line->defective_qty,
                'rejected_qty' => (int) $line->rejected_qty,
                'missing_qty' => (int) $line->missing_qty,
                'qc_reasons' => $line->qc_reasons,
                'invoice_amount' => $line->invoice_amount,
                'unit_price' => $line->unit_price,
                'suggested_bin' => $line->suggested_bin,
            ])
            : collect([[
                'sku' => $qc->sku,
                'po_qty' => (int) $qc->po_qty,
                'invoice_qty' => (int) $qc->invoice_qty,
                'physical_received' => (int) $qc->physical_received,
                'accepted_qty' => (int) $qc->accepted_qty,
                'qc_hold_qty' => (int) $qc->qc_hold_qty,
                'defective_qty' => (int) $qc->defective_qty,
                'rejected_qty' => (int) $qc->rejected_qty,
                'missing_qty' => (int) $qc->missing_qty,
                'qc_reasons' => $qc->qc_reasons,
                'invoice_amount' => $qc->invoice_amount,
                'unit_price' => $qc->unit_price,
                'suggested_bin' => $qc->suggested_bin,
            ]]);

        $qcLines = $qcLines->values()->map(function (array $line, int $index) use ($gate, $putawayAlreadyComplete, $suggestedBin): array {
            $bin = is_array($suggestedBin) ? (string) ($suggestedBin[$index] ?? '') : $suggestedBin;
            if ($putawayAlreadyComplete) {
                $bin = filled($line['suggested_bin'] ?? null)
                    ? (string) $line['suggested_bin']
                    : (string) $gate->final_bin;
            }
            if (trim($bin) === '') {
                $errorKey = is_array($suggestedBin) ? "suggestedBins.{$index}" : 'suggestedBin';
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $errorKey => 'Choose a bin for each SKU before posting the GRN.',
                ]);
            }
            $line['suggested_bin'] = trim($bin);

            return $line;
        });
        $postedBins = $qcLines->pluck('suggested_bin')->unique()->values();
        $postedBin = $postedBins->count() === 1 ? $postedBins->first() : 'Multiple bins';

        // Keep each material's valuation attached to its own SKU. A gate-level
        // invoice total or rate is only a fallback for single-line receipts;
        // it must never be used to blend different PO line prices together.
        $qcLines = $qcLines->map(function (array $line) use ($gate, $purchaseOrder): array {
            $unitPrice = $this->resolveLineRate($gate, (string) $line['sku'], $line['unit_price'] ?? null, $purchaseOrder);
            if ($unitPrice < 0 || ($purchaseOrder?->lines->count() > 1 && $line['unit_price'] === null
                && ! $purchaseOrder->lines->contains(fn ($candidate) => mb_strtolower(trim((string) $candidate->product)) === mb_strtolower(trim((string) $line['sku']))))) {
                throw ValidationException::withMessages([
                    'grn' => "A separate PO unit price could not be matched for SKU {$line['sku']}. Correct the SKU/price mapping before posting this multi-SKU GRN.",
                ]);
            }

            $line['unit_price'] = $unitPrice;
            $line['invoice_amount'] = $line['invoice_amount'] !== null
                ? (float) $line['invoice_amount']
                : round((int) $line['invoice_qty'] * $unitPrice, 2);
            $line['accepted_value'] = round((int) $line['accepted_qty'] * $unitPrice, 2);

            return $line;
        });

        $lineTotals = [
            'po_qty' => $qcLines->sum('po_qty'),
            'invoice_qty' => $qcLines->sum('invoice_qty'),
            'physical_received' => $qcLines->sum('physical_received'),
            'accepted_qty' => $qcLines->sum('accepted_qty'),
            'qc_hold_qty' => $qcLines->sum('qc_hold_qty'),
            'defective_qty' => $qcLines->sum('defective_qty'),
            'rejected_qty' => $qcLines->sum('rejected_qty'),
            'missing_qty' => $qcLines->sum('missing_qty'),
        ];

        $grn = GrnRecord::create([
            'created_by' => auth()->id(),
            'gate_entry_id' => $gate->id,
            'sku' => $qcLines->count() > 1 ? 'MULTI-SKU' : $qcLines->first()['sku'],
            'po_qty' => $lineTotals['po_qty'],
            'invoice_qty' => $lineTotals['invoice_qty'],
            'physical_received' => $lineTotals['physical_received'],
            'accepted_qty' => $lineTotals['accepted_qty'],
            'qc_hold_qty' => $lineTotals['qc_hold_qty'],
            'defective_qty' => $lineTotals['defective_qty'],
            'rejected_qty' => $lineTotals['rejected_qty'],
            'missing_qty' => $lineTotals['missing_qty'],
            'qc_reasons' => $qc->qc_reasons,
            'suggested_bin' => $postedBin,
            'posted' => true,
            // A posted GRN is the vendor-facing receipt. Mark it as
            // notified at the same transaction boundary so the vendor can
            // see the report immediately without a second manual action.
            'vendor_notified_at' => now(),
            'vendor_notified_by' => auth()->id(),
        ]);

        foreach ($qcLines as $line) {
            $siteId = $gate->location_id ?: SiteLocation::idForName($gate->location);
            $warehouseId = WarehouseStockResolver::forItemAtSite($siteId, (string) $line['sku']);
            $grn->lines()->create([
                'gate_entry_id' => $gate->id,
                'sku' => $line['sku'],
                'po_qty' => $line['po_qty'],
                'invoice_qty' => $line['invoice_qty'],
                'physical_received' => $line['physical_received'],
                'accepted_qty' => $line['accepted_qty'],
                'qc_hold_qty' => $line['qc_hold_qty'],
                'defective_qty' => $line['defective_qty'],
                'rejected_qty' => $line['rejected_qty'],
                'missing_qty' => $line['missing_qty'],
                'qc_reasons' => $line['qc_reasons'],
                'suggested_bin' => $line['suggested_bin'],
                'unit_price' => $line['unit_price'],
                'invoice_amount' => $line['invoice_amount'],
                'accepted_value' => $line['accepted_value'],
            ]);

            foreach ([
                ['bucket' => 'available', 'qty' => $line['accepted_qty']],
                ['bucket' => 'defective', 'qty' => $line['defective_qty']],
                ['bucket' => 'rejected', 'qty' => $line['rejected_qty']],
                ['bucket' => 'qcHold', 'qty' => $line['qc_hold_qty']],
            ] as $entry) {
                if ($entry['qty'] > 0) {
                    LedgerEntry::create([
                        'gate_entry_id' => $gate->id,
                        'warehouse_id' => $warehouseId,
                        'sku' => $line['sku'],
                        'bin' => $line['suggested_bin'],
                        'bucket' => $entry['bucket'],
                        'qty' => $entry['qty'],
                        'unit_price' => $line['unit_price'],
                        'extended_value' => round($entry['qty'] * $line['unit_price'], 2),
                    ]);
                }
            }
        }

        $lineInvoiceValue = round((float) $qcLines->sum('invoice_amount'), 2);
        $lineAcceptedValue = round((float) $qcLines->sum('accepted_value'), 2);
        $lineDefectiveValue = round((float) $qcLines->sum(fn (array $line) => $line['defective_qty'] * $line['unit_price']), 2);
        $lineRejectedValue = round((float) $qcLines->sum(fn (array $line) => $line['rejected_qty'] * $line['unit_price']), 2);
        $lineMissingValue = round((float) $qcLines->sum(fn (array $line) => $line['missing_qty'] * $line['unit_price']), 2);
        $lineHoldValue = round((float) $qcLines->sum(fn (array $line) => $line['qc_hold_qty'] * $line['unit_price']), 2);
        // Preserve the supplier's tax-inclusive bill total when the Guard
        // captured it. Otherwise keep the sum of the independently priced SKU lines.
        $invoiceValue = $gate->invoice_amount !== null
            ? (float) $gate->invoice_amount
            : $lineInvoiceValue;
        $effectiveRate = $lineTotals['invoice_qty'] > 0 ? $invoiceValue / $lineTotals['invoice_qty'] : 0;
        $financeRecord = FinanceRecord::create([
            'created_by' => auth()->id(),
            'gate_entry_id' => $gate->id,
            'vendor_name' => $gate->vendor_name ?? 'Unknown Vendor',
            'invoice_number' => $gate->invoice_number,
            // FinanceRecord keeps a single legacy display/connector field;
            // payable and deductions below are calculated from each SKU's own rate.
            'rate_per_unit' => $effectiveRate,
            'invoice_value' => $invoiceValue,
            'accepted_value' => $lineAcceptedValue,
            'deduction_defective' => $lineDefectiveValue,
            'deduction_rejected' => $lineRejectedValue,
            'deduction_missing' => $lineMissingValue,
            'deduction_hold' => $lineHoldValue,
            'final_payable' => $lineAcceptedValue,
            'vendor_status' => 'pending',
        ]);

        $this->threeWayMatch->check($financeRecord);
        $this->issueDebitNoteIfNeeded($financeRecord);

        // GRN posting used to close the gate entry outright -- stock was on
        // the ledger, but nobody had actually put it away to the bin yet.
        // Putaway (Store Exec) is now its own step; the entry only reaches
        // "closed" once that's confirmed.
        $gate->update(['status' => $putawayAlreadyComplete ? 'closed' : 'grn_posted']);

        AuditLogger::log(
            $putawayAlreadyComplete ? 'GRN Check posted after putaway, entry closed' : 'GRN Check posted, stock updated, awaiting putaway',
            "{$gate->gate_no} · ".($qcLines->map(fn (array $line) => $line['sku'].': '.$line['suggested_bin'])->implode(', ')),
            $grn,
        );
        AuditLogger::log('GRN report auto-notified vendor', "{$gate->po_number} · {$gate->gate_no}", $grn);

        app(\App\Services\OperationalEventMailer::class)->notify(
            "GRN posted for {$gate->gate_no}",
            "GRN {$grn->id} was posted for {$gate->gate_no} (PO {$gate->po_number}). Store Executive putaway is the next step.",
            $gate->location_id ?: SiteLocation::idForName($gate->location),
            [\App\Enums\Role::StoreExec],
            '/unloading/putaway',
        );

        return $grn;
    }

    /** One debit note per deduction reason, raised automatically the moment the deduction is known — not left for someone to remember to do manually. */
    private function issueDebitNoteIfNeeded(FinanceRecord $record): void
    {
        foreach ([
            'Defective goods' => $record->deduction_defective,
            'Rejected goods' => $record->deduction_rejected,
            'Missing quantity' => $record->deduction_missing,
        ] as $reason => $amount) {
            if ((float) $amount > 0) {
                DebitNote::create([
                    'finance_record_id' => $record->id,
                    'vendor_name' => $record->vendor_name,
                    'reason' => $reason,
                    'amount' => $amount,
                    'status' => 'issued',
                ]);
            }
        }
    }
}
