<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;

// Ports the React prototype's inline validation in GuardBillScan.tsx —
// same rules, same severities. Runs against real PO Master / Vendor Master
// / SKU Master data instead of a hardcoded mock, so Admin-managed master
// data actually governs what the gate accepts.
class GateValidationService
{
    /**
     * @return array<int, array{code: string, title: string, description: string, severity: string}>
     */
    public function validate(array $form): array
    {
        $issues = [];

        $raise = function (string $code, string $title, string $description, string $severity) use (&$issues) {
            $issues[] = ['code' => $code, 'title' => $title, 'description' => $description, 'severity' => $severity];
        };

        if ($form['entry_type'] !== 'inward') {
            return $issues;
        }

        $po = $form['po_number']
            ? PurchaseOrder::with('lines')->where('po_number', $form['po_number'])->first()
            : null;
        $primaryLine = $po?->primaryLine();
        $vendorOnFile = $po ? VendorMaster::where('vendor_name', $po->vendor_name)->first() : null;

        if (! $form['po_number']) {
            $raise('PO_MISSING', 'PO Missing', 'PO number not found for this inward entry', 'hardFail');
        }

        if (empty($form['vendor_name'])) {
            $raise('VENDOR_MISSING', 'Vendor Missing', 'No vendor name entered for this inward entry', 'hardFail');
        }

        if (! empty($form['invoice_number']) && GateEntry::whereRaw('LOWER(invoice_number) = ?', [strtolower($form['invoice_number'])])->exists()) {
            $raise('DUP_INVOICE', 'Duplicate Invoice', 'Possible duplicate invoice detected', 'hardFail');
        }

        if (GateEntry::where('status', '!=', 'closed')->whereRaw('LOWER(vehicle_number) = ?', [strtolower($form['vehicle_number'])])->exists()) {
            $raise('DUP_VEHICLE', 'Duplicate Vehicle', 'This vehicle already has an open, unclosed gate entry', 'warning');
        }

        $skuEntry = ! empty($form['material'])
            ? SkuMaster::whereRaw('LOWER(sku) = ?', [strtolower($form['material'])])->first()
            : null;

        if (empty($form['material'])) {
            $raise('PRODUCT_LINE_MISSING', 'Product Line Missing', 'No material/SKU line entered for this inward entry', 'hardFail');
        } elseif (! $skuEntry?->mapped) {
            $raise('SKU_NOT_MAPPED', 'SKU Not Mapped', "\"{$form['material']}\" is not in the mapped SKU master — map it before GRN", 'hardFail');
        }

        if ($form['po_number'] && ! $po) {
            $raise('PO_NOT_FOUND', 'PO Not Found', "{$form['po_number']} was not found in PO master data", 'hardFail');
        }

        $amountCalculator = app(PurchaseOrderAmountCalculator::class);
        $productLines = is_array($form['product_lines'] ?? null) ? $form['product_lines'] : [];
        $invoiceQty = is_numeric($form['invoice_qty'] ?? null) ? (float) $form['invoice_qty'] : null;
        $allocations = $po && $invoiceQty !== null
            ? $amountCalculator->allocations($po, $productLines, $invoiceQty)
            : null;

        if ($primaryLine && $po?->lines->count() === 1 && ! empty($form['rate']) && (float) $form['rate'] !== (float) $primaryLine->list_price) {
            $raise('RATE_MISMATCH', 'Rate Mismatch', "Entered rate ₹{$form['rate']}/unit does not match PO rate ₹{$primaryLine->list_price}/unit", 'redFlag');
        }

        if ($po && $invoiceQty !== null) {
            if ($allocations === null) {
                $raise('PO_LINE_ALLOCATION_MISSING', 'PO Line Allocation Missing', 'This multi-item shipment cannot be safely matched to the PO lines. Fetch the PO or vendor submission again so every shipped SKU and quantity is included.', 'hardFail');
            } else {
                $receivedByLine = [];
                $unallocatedPreviousQty = 0.0;
                foreach (GateEntry::where('po_number', $po->po_number)->where('entry_type', 'inward')->get(['invoice_qty', 'product_lines']) as $previousGate) {
                    $previousAllocations = $amountCalculator->allocations(
                        $po,
                        is_array($previousGate->product_lines) ? $previousGate->product_lines : [],
                        is_numeric($previousGate->invoice_qty) ? (float) $previousGate->invoice_qty : null,
                    );
                    if ($previousAllocations === null) {
                        $unallocatedPreviousQty += (float) ($previousGate->invoice_qty ?? 0);

                        continue;
                    }
                    foreach ($previousAllocations as $lineId => $quantity) {
                        $receivedByLine[$lineId] = ($receivedByLine[$lineId] ?? 0) + $quantity;
                    }
                }

                foreach ($allocations as $lineId => $quantity) {
                    $poLine = $po->lines->firstWhere('id', $lineId);
                    if (! $poLine) {
                        continue;
                    }
                    if ($quantity > (float) $poLine->quantity) {
                        $raise('QTY_MISMATCH', 'Quantity Mismatch', "Invoice quantity {$quantity} for {$poLine->product} exceeds PO quantity {$poLine->quantity}", 'redFlag');
                    }
                    $projectedQty = ($receivedByLine[$lineId] ?? 0) + $quantity;
                    if ($projectedQty > (float) $poLine->quantity) {
                        $raise('QTY_OVER_RECEIPT', 'Quantity Exceeds PO', "This bill would take cumulative inward quantity for {$poLine->product} to {$projectedQty}, above PO qty {$poLine->quantity}", 'redFlag');
                    }
                }

                $totalOrdered = (float) $po->lines->sum('quantity');
                $totalAlreadyReceived = array_sum($receivedByLine) + $unallocatedPreviousQty;
                $currentTotal = array_sum($allocations);
                if ($unallocatedPreviousQty > 0 && $totalAlreadyReceived + $currentTotal > $totalOrdered) {
                    $raise('QTY_OVER_RECEIPT', 'Quantity Exceeds PO', 'The new bill would take total inward quantity above the PO quantity after including earlier legacy receipts.', 'redFlag');
                }
            }
        }

        if (! empty($form['invoice_amount']) && $invoiceQty !== null && $po) {
            if ($allocations !== null) {
                $expected = $amountCalculator->amounts($po, $allocations);
                $tolerance = max(5.0, $expected['net'] * 0.01);
                if (abs((float) $form['invoice_amount'] - $expected['net']) > $tolerance
                    && abs((float) $form['invoice_amount'] - $expected['total']) > $tolerance) {
                    $raise('INVOICE_AMOUNT_MISMATCH', 'Invoice Amount Mismatch', 'Entered amount ₹'.number_format((float) $form['invoice_amount'], 2).' does not match the allocated PO net ₹'.number_format($expected['net'], 2).' or tax-inclusive ₹'.number_format($expected['total'], 2), 'redFlag');
                }
            }
        }

        if ($vendorOnFile && ! empty($form['vendor_gst']) && strtoupper($form['vendor_gst']) !== strtoupper($vendorOnFile->gst_number)) {
            $raise('GST_MISMATCH', 'GST Mismatch', "Entered GST does not match the GST on file for {$vendorOnFile->vendor_name}", 'redFlag');
        }

        $vendorHasLrPod = $form['po_number']
            ? VendorSubmission::where('po_number', $form['po_number'])->where('has_lr_pod', true)->exists()
            : false;
        if ($form['po_number'] && ! $vendorHasLrPod) {
            // Downgraded from redFlag to warning: this fired on almost every
            // delivery (LR/POD often legitimately arrives with the driver or
            // gets uploaded after the gate entry, not before it) and was
            // blocking Store Manager's approval on entries that had nothing
            // actually wrong with them -- confirmed live, repeatedly tripped
            // up testing. Still visible and flagged, just not a hard stop.
            $raise('POD_LR_EARLY', 'POD/LR Missing At Gate', 'Vendor has not pre-uploaded LR/POD for this PO — confirm at unloading', 'warning');
        }

        if (empty($form['gps'])) {
            $raise('GPS_MISSING', 'GPS Missing', 'Gate entry saved without GPS location', 'warning');
        }

        return $issues;
    }

    public function isBlocking(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue['severity'], ['hardFail', 'redFlag'], true)) {
                return true;
            }
        }

        return false;
    }
}
