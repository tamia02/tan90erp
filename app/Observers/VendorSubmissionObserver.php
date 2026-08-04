<?php

namespace App\Observers;

use App\Models\VendorSubmission;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class VendorSubmissionObserver
{
    public function saved(VendorSubmission $submission): void
    {
        try {
            app(ZohoService::class)->pushVendorSubmission($submission);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Invoice push failed from VendorSubmission observer', [
                'vendor_submission_id' => $submission->id,
                'invoice_number' => $submission->invoice_number,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
