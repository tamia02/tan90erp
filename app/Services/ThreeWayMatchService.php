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

        if ($po->lines->isEmpty()) {
            return $this->settle($record, 'exception', "PO {$gate->po_number} has no line items to match against.");
        }

        $notes = [];
        $calculator = app(PurchaseOrderAmountCalculator::class);
        $allocations = $calculator->allocations(
            $po,
            is_array($gate->product_lines) ? $gate->product_lines : [],
            is_numeric($gate->invoice_qty) ? (float) $gate->invoice_qty : null,
        );

        if ($allocations !== null && count($allocations) === 1 && $gate->rate !== null) {
            $line = $po->lines->firstWhere('id', array_key_first($allocations));
            if ($line && abs((float) $gate->rate - (float) $line->list_price) > self::RATE_TOLERANCE) {
                $notes[] = "Invoice rate ₹{$gate->rate} differs from PO rate ₹{$line->list_price}.";
            }
        }

        if ($allocations === null && ($gate->invoice_qty !== null || $gate->invoice_amount !== null)) {
            $notes[] = 'Shipment SKU quantities cannot be allocated to the PO lines; re-fetch the PO details before finance can clear this invoice.';
        } elseif ($allocations !== null) {
            foreach ($allocations as $lineId => $quantity) {
                $line = $po->lines->firstWhere('id', $lineId);
                if ($line && $quantity > (float) $line->quantity) {
                    $notes[] = "Invoice quantity {$quantity} for {$line->product} exceeds PO quantity {$line->quantity}.";
                }
            }
        }

        if ($gate->invoice_amount !== null && $allocations !== null) {
            $expected = $calculator->amounts($po, $allocations);
            $tolerance = max(self::RATE_TOLERANCE, $expected['net'] * 0.01);

            if (abs((float) $gate->invoice_amount - $expected['net']) > $tolerance
                && abs((float) $gate->invoice_amount - $expected['total']) > $tolerance) {
                $notes[] = "Invoice amount ₹{$gate->invoice_amount} doesn't reconcile with allocated PO net ₹".number_format($expected['net'], 2).' or tax-inclusive ₹'.number_format($expected['total'], 2).'.';
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
