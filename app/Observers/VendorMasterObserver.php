<?php

namespace App\Observers;

use App\Models\VendorMaster;
use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class VendorMasterObserver
{
    public function saved(VendorMaster $vendor): void
    {
        $inventoryActive = app(ZohoInventoryService::class)->isActive();

        if ($inventoryActive) {
            try {
                app(ZohoInventoryService::class)->pushVendorContact($vendor);
            } catch (\Throwable $exception) {
                Log::warning('Zoho Inventory vendor push failed from VendorMaster observer', [
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->vendor_name,
                    'error' => $exception->getMessage(),
                ]);
            }

            return;
        }

        // CRM stays the vendor master sync until Zoho Inventory is active.
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
