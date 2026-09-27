<?php

namespace App\Observers;

use App\Models\SkuMaster;
use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class SkuMasterObserver
{
    public function saved(SkuMaster $sku): void
    {
        $inventoryActive = app(ZohoInventoryService::class)->isActive();

        if ($inventoryActive) {
            try {
                app(ZohoInventoryService::class)->pushItem($sku);
            } catch (\Throwable $exception) {
                Log::warning('Zoho Inventory item push failed from SkuMaster observer', [
                    'sku_id' => $sku->id,
                    'sku' => $sku->sku,
                    'error' => $exception->getMessage(),
                ]);
            }

            return;
        }

        // CRM stays the SKU master sync until Zoho Inventory is active.
        try {
            app(ZohoService::class)->pushSkuMaster($sku);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Product push failed from SkuMaster observer', [
                'sku_id' => $sku->id,
                'sku' => $sku->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
