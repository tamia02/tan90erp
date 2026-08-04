<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncZohoPurchaseOrders extends Command
{
    protected $signature = 'zoho:sync-purchase-orders {--fresh : Ignore the saved checkpoint and scan the latest Zoho records again}';

    protected $description = 'Sync recently modified Zoho CRM purchase orders into the local PO master. Superseded once Zoho Inventory is active — Inventory has no inbound PO sync yet, so this stops rather than pulling stale CRM copies back in.';

    public function handle(ZohoService $zoho, ZohoInventoryService $inventory): int
    {
        if ($inventory->isActive()) {
            $this->info('Skipped — Zoho Inventory is active, CRM is no longer the PO source of truth.');

            return self::SUCCESS;
        }

        $checkpointKey = 'zoho_purchase_orders_last_modified';
        $since = $this->option('fresh') ? null : Cache::get($checkpointKey);

        $result = $zoho->syncRecentlyModifiedPurchaseOrders($since);

        if ($result['last_modified'] && $result['failed'] === 0) {
            Cache::forever($checkpointKey, $result['last_modified']);
        }

        $this->info(sprintf(
            'Zoho PO sync complete: %d synced, %d skipped, %d failed. Checkpoint: %s',
            $result['synced'],
            $result['skipped'],
            $result['failed'],
            $result['failed'] === 0 ? ($result['last_modified'] ?: 'none') : 'unchanged due to failures',
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
