<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Models\QcResult;

// Ports the React prototype's SAVE_QC_RESULT reducer case — QC Check
// produces the accept/hold/defective/rejected split with reasons. Does NOT
// touch stock; GRN Check (GrnPostingService) reads this and is the only
// thing that posts to the ledger.
class QcService
{
    public function recordResult(GateEntry $gate, string $sku, int $poQty, int $invoiceQty, array $split, ?string $qcReasons = null): QcResult
    {
        $physicalReceived = $split['accepted'] + $split['qcHold'] + $split['defective'] + $split['rejected'];
        $missing = max($invoiceQty - $physicalReceived, 0);

        $result = QcResult::create([
            'gate_entry_id' => $gate->id,
            'sku' => $sku,
            'po_qty' => $poQty,
            'invoice_qty' => $invoiceQty,
            'physical_received' => $physicalReceived,
            'accepted_qty' => $split['accepted'],
            'qc_hold_qty' => $split['qcHold'],
            'defective_qty' => $split['defective'],
            'rejected_qty' => $split['rejected'],
            'missing_qty' => $missing,
            'qc_reasons' => $qcReasons,
            // A rejection automatically opens a purchase return for the
            // vendor to action — surfaced via NotificationCenter::vendor()
            // and the vendor dashboard's "Purchase Return" button.
            'return_status' => $split['rejected'] > 0 ? 'pending' : null,
            'return_requested_at' => $split['rejected'] > 0 ? now() : null,
        ]);

        $gate->update(['status' => 'qc_done']);

        AuditLogger::log(
            'QC Check recorded',
            "{$gate->gate_no} · accepted {$split['accepted']}, defective {$split['defective']}, rejected {$split['rejected']} · sent to GRN Check",
            $result,
        );

        if ($split['rejected'] > 0) {
            AuditLogger::log(
                'Purchase return notification sent to vendor',
                "{$gate->gate_no} · {$gate->vendor_name} · rejected {$split['rejected']}".($qcReasons ? " · {$qcReasons}" : ''),
                $result,
            );
        }

        return $result;
    }
}
