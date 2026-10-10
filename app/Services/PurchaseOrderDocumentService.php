<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\VendorMaster;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

class PurchaseOrderDocumentService
{
    /** @return array<string, mixed> */
    public function data(PurchaseOrder $po): array
    {
        $vendor = VendorMaster::where('vendor_name', $po->vendor_name)->first();
        $taxService = app(PurchaseOrderTaxService::class);
        // One QR for everyone: vendors land on their PO, staff on the PO tracker.
        $lookupUrl = route('po.open', $po->po_number);
        $qr = (new Builder(
            writer: new SvgWriter(),
            data: $lookupUrl,
            size: 180,
            margin: 8,
        ))->build();

        return [
            'po' => $po->loadMissing('lines'),
            'vendor' => $vendor,
            'qrDataUri' => $qr->getDataUri(),
            'lookupUrl' => $lookupUrl,
            'companyState' => $taxService->companyState(),
            'lineTaxes' => $po->lines->mapWithKeys(fn ($line) => [
                $line->id => $taxService->breakdown(
                    (float) $line->quantity * (float) $line->list_price,
                    (float) $line->discount,
                    (float) ($line->gst_rate ?? 0),
                    $po->shipping_state,
                    (bool) $po->reverse_charge,
                ),
            ]),
            'totalInWords' => $this->amountInWords($po->grandTotal()),
            // "SKU : TNRMPCKCL" under each item, as on the Zoho PO.
            'skuCodes' => \App\Models\SkuMaster::whereIn('sku', $po->lines->pluck('product'))->whereNotNull('product_code')
                ->pluck('product_code', 'sku')->mapWithKeys(fn ($code, $sku) => [mb_strtolower(trim($sku)) => $code]),
            'deliverGstin' => $this->deliverGstin($po),
        ];
    }

    // GSTIN of the delivery location when known, else the company's own.
    private function deliverGstin(PurchaseOrder $po): ?string
    {
        $location = $po->warehouse_location
            ? \App\Models\Tan90\MasterData\Location::where('name', $po->warehouse_location)->orWhere('code', $po->warehouse_location)->first()
            : null;

        return $location?->gstin ?: config('company.gstin');
    }

    private function amountInWords(float $amount): string
    {
        $rupees = (int) floor(max($amount, 0));
        $paise = (int) round((max($amount, 0) - $rupees) * 100);
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }

        $words = $this->numberInWords($rupees).' Rupees';
        if ($paise > 0) {
            $words .= ' and '.$this->numberInWords($paise).' Paise';
        }

        return $words.' Only';
    }

    private function numberInWords(int $number): string
    {
        if ($number === 0) return 'Zero';
        if ($number >= 10000000) return $this->numberInWords(intdiv($number, 10000000)).' Crore'.($number % 10000000 ? ' '.$this->numberInWords($number % 10000000) : '');
        if ($number >= 100000) return $this->numberInWords(intdiv($number, 100000)).' Lakh'.($number % 100000 ? ' '.$this->numberInWords($number % 100000) : '');
        if ($number >= 1000) return $this->numberInWords(intdiv($number, 1000)).' Thousand'.($number % 1000 ? ' '.$this->numberInWords($number % 1000) : '');
        if ($number >= 100) return $this->numberInWords(intdiv($number, 100)).' Hundred'.($number % 100 ? ' '.$this->numberInWords($number % 100) : '');

        $ones = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = [20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'];
        if ($number < 20) return $ones[$number];

        $ten = intdiv($number, 10) * 10;

        return $tens[$ten].($number % 10 ? ' '.$ones[$number % 10] : '');
    }
}
