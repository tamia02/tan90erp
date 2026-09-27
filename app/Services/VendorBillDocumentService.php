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
        ];
    }
}
