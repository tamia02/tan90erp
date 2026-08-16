<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PushZohoInventoryData extends Command
{
    protected $signature = 'zoho:push-inventory-data {--limit=200 : Max changed rows to push per entity in this run}';

    protected $description = 'Push Tan90 SKU/Vendor Master, Purchase Orders, vendor bills, and GRN receiving into Zoho Inventory (Items, Contacts, Purchase Orders, Bills, Purchase Receives). Only pushes rows changed since the last successful run per entity.';

    public function handle(ZohoInventoryService $inventory): int
    {
        $result = $inventory->pushOperationalData((int) $this->option('limit'));

        if ($result['skipped']) {
            $this->info('Zoho Inventory push skipped — organization_id and/or refresh token not configured yet.');

            return self::SUCCESS;
        }

        Cache::put('zoho_inventory_last_run:push-data', [
            'at' => now()->toISOString(),
            'failed' => $result['failed'],
            'summary' => "{$result['items']} items, {$result['vendors']} vendors, {$result['purchase_orders']} POs, {$result['bills']} bills, {$result['purchase_receives']} receives",
        ], now()->addHours(2));

        $this->info(sprintf(
            'Zoho Inventory push complete: %d items, %d vendors, %d purchase orders, %d bills, %d purchase receives, %d failed.',
            $result['items'],
            $result['vendors'],
            $result['purchase_orders'],
            $result['bills'],
            $result['purchase_receives'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
