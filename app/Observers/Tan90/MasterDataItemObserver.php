<?php

namespace App\Observers\Tan90;

use App\Models\Tan90\MasterData\Item;
use App\Services\ZohoInventoryService;
use Illuminate\Support\Facades\Log;

class MasterDataItemObserver
{
    public function saved(Item $item): void
    {
        $inventory = app(ZohoInventoryService::class);

        if (! $inventory->isActive()) {
            return;
        }

        try {
            if (! $inventory->pushMasterDataItem($item)) {
                Log::warning('Zoho Inventory item push failed from Master Data Item observer', [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'error' => $inventory->lastError(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Zoho Inventory item push exception from Master Data Item observer', [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
