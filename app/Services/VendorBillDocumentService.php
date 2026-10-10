<?php

namespace App\Services;

use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\URL;

/**
 * Builds the canonical TAN90 vendor bill/dispatch document from the saved
 * submission.  The PDF never trusts browser-formatted totals or a manually
 * entered PO description; it reads the persisted submission and its PO.
 */
class VendorBillDocumentService
{
    /** @return array<string, mixed> */
    public function data(VendorSubmission $submission): array
    {
        $submission->loadMissing('purchaseOrder.lines');
        $po = $submission->purchaseOrder;
        $vendor = VendorMaster::where('vendor_name', $submission->vendor_name)->first();
        $shareUrl = URL::temporarySignedRoute(
            'vendor.bill.share',
            now()->addDay(),
            ['submission' => $submission->getKey()],
        );
        $qr = (new Builder(
            writer: new SvgWriter(),
            data: $shareUrl,
            size: 180,
            margin: 8,
        ))->build();

        return [
            'submission' => $submission,
            'po' => $po,
            'vendor' => $vendor,
            'shareUrl' => $shareUrl,
            'qrDataUri' => $qr->getDataUri(),
            ...$this->billLines($submission, $po),
            'issuer' => \App\Support\PdfDocument::issuer($po?->warehouse_location
                ? \App\Models\Tan90\MasterData\Location::where('name', $po->warehouse_location)->orWhere('code', $po->warehouse_location)->first()
                : null),
        ];
    }

    /**
     * Zoho Bill lines: the PO lines the vendor billed (with PO rate and GST),
     * or one line from the submission when it has no per-line quantities.
     *
     * @return array{billLines: array<int, array>, taxRows: array<string, array>, subTotal: float, billTotal: float}
     */
    private function billLines(VendorSubmission $submission, ?\App\Models\PurchaseOrder $po): array
    {
        $quantities = is_array($submission->line_quantities) ? $submission->line_quantities : [];
        $lines = [];
        $taxRows = [];
        if ($po && $quantities) {
            $taxService = app(PurchaseOrderTaxService::class);
            $codes = \App\Models\SkuMaster::whereIn('sku', $po->lines->pluck('product'))->whereNotNull('product_code')->pluck('product_code', 'sku');
            foreach ($po->lines as $line) {
                $qty = (float) ($quantities[$line->id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $amount = $qty * (float) $line->list_price;
                $lines[] = ['name' => $line->product, 'code' => $codes[$line->product] ?? null, 'hsn' => $line->hsn_sac, 'qty' => $qty, 'uom' => $line->uom, 'rate' => (float) $line->list_price, 'amount' => $amount];
                $t = $taxService->breakdown($amount, 0, (float) ($line->gst_rate ?? 0), $po->shipping_state, (bool) $po->reverse_charge);
                $rate = rtrim(rtrim(number_format((float) ($t['rate'] ?? 0), 2), '0'), '.');
                if (($t['igst'] ?? 0) > 0) {
                    $taxRows['IGST'.$rate] = ['label' => "IGST{$rate} ({$rate}%)", 'amount' => ($taxRows['IGST'.$rate]['amount'] ?? 0) + $t['igst']];
                } elseif (($t['cgst'] ?? 0) > 0 || ($t['sgst'] ?? 0) > 0) {
                    $half = rtrim(rtrim(number_format((float) ($t['rate'] ?? 0) / 2, 2), '0'), '.');
                    $taxRows['SGST'.$half] = ['label' => "SGST{$half} ({$half}%)", 'amount' => ($taxRows['SGST'.$half]['amount'] ?? 0) + $t['sgst']];
                    $taxRows['CGST'.$half] = ['label' => "CGST{$half} ({$half}%)", 'amount' => ($taxRows['CGST'.$half]['amount'] ?? 0) + $t['cgst']];
                }
            }
        }
        if (! $lines) {
            $qty = (float) ($submission->invoice_qty ?? 0);
            $amount = (float) ($submission->invoice_amount ?? 0);
            // No amount typed by the vendor: price it at the PO rate.
            $rate = $qty > 0 && $amount > 0 ? $amount / $qty : (float) ($po?->primaryLine()?->list_price ?? 0);
            $lines[] = ['name' => $submission->material ?: ($po?->primaryLine()?->product ?? '—'), 'code' => null, 'hsn' => $po?->primaryLine()?->hsn_sac,
                'qty' => $qty, 'uom' => $po?->primaryLine()?->uom, 'rate' => $rate, 'amount' => $amount > 0 ? $amount : $qty * $rate];
            $taxRows = [];
        }
        $subTotal = array_sum(array_column($lines, 'amount'));
        $computed = $subTotal + array_sum(array_column($taxRows, 'amount'));

        return ['billLines' => $lines, 'taxRows' => $taxRows, 'subTotal' => $subTotal,
            'billTotal' => (float) $submission->invoice_amount > 0 ? (float) $submission->invoice_amount : $computed];
    }
}
