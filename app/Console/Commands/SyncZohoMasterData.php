<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Console\Command;

class SyncZohoMasterData extends Command
{
    protected $signature = 'zoho:sync-master-data {--limit=200 : Maximum vendors/products to read per module}';

    protected $description = 'Sync Zoho CRM Vendors and Products into Tan90 master data. Superseded once Zoho Inventory is active — Inventory has no inbound master-data sync yet, so this stops rather than pulling stale CRM copies back in.';

    public function handle(ZohoService $zoho, ZohoInventoryService $inventory): int
    {
        if ($inventory->isActive()) {
            $this->info('Skipped — Zoho Inventory is active, CRM is no longer the vendor/SKU master source of truth.');

            return self::SUCCESS;
        }

        $result = $zoho->syncMasterData((int) $this->option('limit'));

        $this->info("Zoho master-data sync complete: {$result['vendors']} vendors, {$result['products']} products, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
