<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Console\Command;

class PushZohoMasterData extends Command
{
    protected $signature = 'zoho:push-master-data';

    protected $description = 'Push Tan90 Vendor Master and SKU Master records into Zoho CRM Vendors and Products. Superseded by zoho:push-inventory-data once Zoho Inventory is active.';

    public function handle(ZohoService $zoho, ZohoInventoryService $inventory): int
    {
        if ($inventory->isActive()) {
            $this->info('Skipped — Zoho Inventory is active, vendor/SKU master sync now runs via zoho:push-inventory-data.');

            return self::SUCCESS;
        }

        $result = $zoho->pushMasterData();

        $this->info("Zoho master-data push complete: {$result['vendors']} vendors, {$result['products']} products, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
