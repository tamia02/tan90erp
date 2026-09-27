<?php

namespace App\Observers\Tan90;

use App\Models\Tan90\MasterData\Vendor;
use App\Services\ZohoInventoryService;
use Illuminate\Support\Facades\Log;

// Client's own call, reversing the earlier "push only after approval"
// decision: vendors/items/customers created in Master Data push to Zoho
// immediately on save, matching how the legacy VendorMaster/SkuMaster
// screens already behave (VendorMasterObserver/SkuMasterObserver).
class MasterDataVendorObserver
{
    public function saved(Vendor $vendor): void
    {
        $inventory = app(ZohoInventoryService::class);

        if (! $inventory->isActive()) {
            return;
        }

        try {
            if (! $inventory->pushMasterDataVendor($vendor)) {
                Log::warning('Zoho Inventory vendor push failed from Master Data Vendor observer', [
                    'vendor_id' => $vendor->id,
                    'name' => $vendor->name,
                    'error' => $inventory->lastError(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Zoho Inventory vendor push exception from Master Data Vendor observer', [
                'vendor_id' => $vendor->id,
                'name' => $vendor->name,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
