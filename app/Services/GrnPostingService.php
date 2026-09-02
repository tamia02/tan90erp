<?php

namespace App\Services;

use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\LedgerEntry;

// Ports the React prototype's SAVE_GRN reducer case — reads the gate's
// QcResult (produced separately by QC Check) and is the ONLY thing that
// posts to the stock ledger and creates the finance payable record. This
// separation (QC splits, GRN posts) is the client's explicit "don't merge
// modules" requirement.
class GrnPostingService
{
    private const RATE_PER_UNIT = 42; // matches the React prototype's placeholder rate

    public function __construct(private readonly ThreeWayMatchService $threeWayMatch) {}

    public function post(GateEntry $gate, string $suggestedBin): ?GrnRecord
    {
        $qc = $gate->qcResult;
        if (! $qc) {
            return null;
        }

        $grn = GrnRecord::create([
            'created_by' => auth()->id(),
            'gate_entry_id' => $gate->id,
            'sku' => $qc->sku,
            'po_qty' => $qc->po_qty,
            'invoice_qty' => $qc->invoice_qty,
            'physical_received' => $qc->physical_received,
            'accepted_qty' => $qc->accepted_qty,
            'qc_hold_qty' => $qc->qc_hold_qty,
            'defective_qty' => $qc->defective_qty,
            'rejected_qty' => $qc->rejected_qty,
            'missing_qty' => $qc->missing_qty,
            'qc_reasons' => $qc->qc_reasons,
            'suggested_bin' => $suggestedBin,
            'posted' => true,
        ]);

        foreach ([
            ['bucket' => 'available', 'qty' => $qc->accepted_qty],
            ['bucket' => 'defective', 'qty' => $qc->defective_qty],
            ['bucket' => 'rejected', 'qty' => $qc->rejected_qty],
            ['bucket' => 'qcHold', 'qty' => $qc->qc_hold_qty],
        ] as $entry) {
            if ($entry['qty'] > 0) {
                LedgerEntry::create([
                    'gate_entry_id' => $gate->id,
                    'sku' => $qc->sku,
                    'bin' => $suggestedBin,
                    'bucket' => $entry['bucket'],
                    'qty' => $entry['qty'],
                ]);
            }
        }

        $rate = self::RATE_PER_UNIT;
        $financeRecord = FinanceRecord::create([
            'created_by' => auth()->id(),
            'gate_entry_id' => $gate->id,
            'vendor_name' => $gate->vendor_name ?? 'Unknown Vendor',
            'invoice_number' => $gate->invoice_number,
            'rate_per_unit' => $rate,
            'invoice_value' => $qc->invoice_qty * $rate,
            'accepted_value' => $qc->accepted_qty * $rate,
            'deduction_defective' => $qc->defective_qty * $rate,
            'deduction_rejected' => $qc->rejected_qty * $rate,
            'deduction_missing' => $qc->missing_qty * $rate,
            'final_payable' => $qc->accepted_qty * $rate,
            'vendor_status' => 'pending',
        ]);

        $this->threeWayMatch->check($financeRecord);
        $this->issueDebitNoteIfNeeded($financeRecord);

        $gate->update(['status' => 'closed']);

        AuditLogger::log('GRN Check posted, stock updated', "{$gate->gate_no} · bin {$suggestedBin}", $grn);

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
