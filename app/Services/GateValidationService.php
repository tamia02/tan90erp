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

        if ($primaryLine && ! empty($form['rate']) && (float) $form['rate'] !== (float) $primaryLine->list_price) {
            $raise('RATE_MISMATCH', 'Rate Mismatch', "Entered rate ₹{$form['rate']}/unit does not match PO rate ₹{$primaryLine->list_price}/unit", 'redFlag');
        }

        if ($primaryLine && ! empty($form['invoice_qty']) && (int) $form['invoice_qty'] !== (int) $primaryLine->quantity) {
            $raise('QTY_MISMATCH', 'Quantity Mismatch', "Invoice qty {$form['invoice_qty']} does not match PO qty {$primaryLine->quantity}", 'redFlag');
        }

        if ($vendorOnFile && ! empty($form['vendor_gst']) && strtoupper($form['vendor_gst']) !== strtoupper($vendorOnFile->gst_number)) {
            $raise('GST_MISMATCH', 'GST Mismatch', "Entered GST does not match the GST on file for {$vendorOnFile->vendor_name}", 'redFlag');
        }

        $vendorHasLrPod = $form['po_number']
            ? VendorSubmission::where('po_number', $form['po_number'])->where('has_lr_pod', true)->exists()
            : false;
        if ($form['po_number'] && ! $vendorHasLrPod) {
            $raise('POD_LR_EARLY', 'POD/LR Missing At Gate', 'Vendor has not pre-uploaded LR/POD for this PO — confirm at unloading', 'redFlag');
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
