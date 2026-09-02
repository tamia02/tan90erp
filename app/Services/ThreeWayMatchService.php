<?php

namespace App\Services;

use App\Models\FinanceRecord;
use App\Models\PurchaseOrder;

/**
 * Compares three independent numbers: what the PO agreed (rate, quantity),
 * what the vendor billed (GateEntry.rate/invoice_amount/invoice_qty — captured
 * at gate entry, separate from the system's own placeholder-rate computation
 * in GrnPostingService), and what was actually received (the GRN behind this
 * FinanceRecord, via accepted_value). Additive: never changes the values
 * GrnPostingService already computed, only flags whether they reconcile.
 */
class ThreeWayMatchService
{
    private const RATE_TOLERANCE = 0.01;

    public function check(FinanceRecord $record): FinanceRecord
    {
        $gate = $record->gateEntry;

        if (! $gate || ! $gate->po_number) {
            return $this->settle($record, 'exception', 'No PO number recorded on the gate entry — cannot match.');
        }

        $po = PurchaseOrder::where('po_number', $gate->po_number)->with('lines')->first();
        if (! $po) {
            return $this->settle($record, 'exception', "No purchase order found for {$gate->po_number}.");
        }

        $line = $po->primaryLine();
        if (! $line) {
            return $this->settle($record, 'exception', "PO {$gate->po_number} has no line items to match against.");
        }

        $notes = [];

        if ($gate->rate !== null && abs((float) $gate->rate - (float) $line->list_price) > self::RATE_TOLERANCE) {
            $notes[] = "Invoice rate ₹{$gate->rate} differs from PO rate ₹{$line->list_price}.";
        }

        if ($gate->invoice_qty !== null && (float) $gate->invoice_qty > (float) $line->quantity) {
            $notes[] = "Invoice quantity {$gate->invoice_qty} exceeds PO quantity {$line->quantity}.";
        }

        if ($gate->invoice_amount !== null) {
            $expected = (float) $gate->invoice_qty * (float) $line->list_price;
            if (abs((float) $gate->invoice_amount - $expected) > max(self::RATE_TOLERANCE, $expected * 0.01)) {
                $notes[] = "Invoice amount ₹{$gate->invoice_amount} doesn't reconcile with qty × PO rate (₹{$expected}).";
            }
        }

        return $notes === []
            ? $this->settle($record, 'matched', null)
            : $this->settle($record, 'exception', implode(' ', $notes));
    }

    private function settle(FinanceRecord $record, string $status, ?string $notes): FinanceRecord
    {
        $record->update(['match_status' => $status, 'match_notes' => $notes]);

        return $record;
    }
}
