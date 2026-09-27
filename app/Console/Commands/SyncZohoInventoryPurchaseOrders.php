<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncZohoInventoryPurchaseOrders extends Command
{
    protected $signature = 'zoho:sync-inventory-purchase-orders {--fresh : Ignore the saved checkpoint and scan the latest Zoho Inventory records again}';

    protected $description = 'Sync recently modified Zoho Inventory purchase orders into the local PO master.';

    public function handle(ZohoInventoryService $inventory): int
    {
        if (! $inventory->isActive()) {
            $this->info('Skipped — Zoho Inventory is not active yet.');

            return self::SUCCESS;
        }

        $checkpointKey = 'zoho_inventory_purchase_orders_last_modified';
        $since = $this->option('fresh') ? null : Cache::get($checkpointKey);

        $result = $inventory->syncRecentlyModifiedPurchaseOrders($since);

        if ($result['last_modified'] && $result['failed'] === 0) {
            Cache::forever($checkpointKey, $result['last_modified']);
        }

        Cache::put('zoho_inventory_last_run:sync-purchase-orders', [
            'at' => now()->toISOString(),
            'failed' => $result['failed'],
            'summary' => "{$result['synced']} synced, {$result['skipped']} skipped",
        ], now()->addHours(2));

        $this->info(sprintf(
            'Zoho Inventory PO sync complete: %d synced, %d skipped, %d failed. Checkpoint: %s',
            $result['synced'],
            $result['skipped'],
            $result['failed'],
            $result['failed'] === 0 ? ($result['last_modified'] ?: 'none') : 'unchanged due to failures',
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
