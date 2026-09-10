<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncZohoInventoryMasterData extends Command
{
    protected $signature = 'zoho:sync-inventory-master-data {--limit=200 : Maximum vendors/items/customers to read per call}';

    protected $description = 'Sync Zoho Inventory Contacts (vendors, customers) and Items into Tan90 Vendor/SKU/Customer Master.';

    public function handle(ZohoInventoryService $inventory): int
    {
        if (! $inventory->isActive()) {
            $this->info('Skipped — Zoho Inventory is not active yet.');

            return self::SUCCESS;
        }

        $result = $inventory->syncMasterData((int) $this->option('limit'));

        Cache::put('zoho_inventory_last_run:sync-master-data', [
            'at' => now()->toISOString(),
            'failed' => $result['failed'],
            'summary' => "{$result['vendors']} vendors, {$result['items']} items, {$result['customers']} customers",
        ], now()->addHours(2));

        $this->info("Zoho Inventory master-data sync complete: {$result['vendors']} vendors, {$result['items']} items, {$result['customers']} customers, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
