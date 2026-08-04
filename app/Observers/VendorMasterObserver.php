<?php

namespace App\Observers;

use App\Models\VendorMaster;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class VendorMasterObserver
{
    public function saved(VendorMaster $vendor): void
    {
        try {
            app(ZohoService::class)->pushVendorMaster($vendor);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Vendor push failed from VendorMaster observer', [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->vendor_name,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
