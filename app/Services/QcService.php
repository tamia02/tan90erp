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
    public function recordResult(GateEntry $gate, string $sku, int $poQty, int $invoiceQty, array $split, ?string $qcReasons = null, ?string $holdReason = null, ?string $holdDocumentPath = null): QcResult
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
            'hold_reason' => $split['qcHold'] > 0 ? $holdReason : null,
            'hold_document_path' => $split['qcHold'] > 0 ? $holdDocumentPath : null,
            // A rejection automatically opens a purchase return for the
            // vendor to action — surfaced via NotificationCenter::vendor()
            // and the vendor dashboard's "Purchase Return" button.
            'return_status' => $split['rejected'] > 0 ? 'pending' : null,
            'return_requested_at' => $split['rejected'] > 0 ? now() : null,
        ]);

        // A full rejection (nothing accepted/held/defective, everything
        // rejected) has nothing left for Store Manager to post — skip GRN
        // Check entirely and go straight to notifying the vendor.
        $fullyRejected = $split['accepted'] === 0 && $split['qcHold'] === 0 && $split['defective'] === 0 && $split['rejected'] > 0;

        $gate->update(['status' => $fullyRejected ? 'rejected' : 'qc_done']);

        AuditLogger::log(
            'QC Check recorded',
            "{$gate->gate_no} · accepted {$split['accepted']}, defective {$split['defective']}, rejected {$split['rejected']}"
                .($fullyRejected ? ' · fully rejected, GRN Check skipped' : ' · sent to GRN Check'),
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
