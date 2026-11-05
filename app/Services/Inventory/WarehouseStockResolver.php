<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\Warehouse;
use Illuminate\Support\Collection;

/** Resolve the operational warehouse for legacy flows that still select a site. */
class WarehouseStockResolver
{
    public static function forItemAtSite(?int $siteId, InventoryItem|string|null $item): ?int
    {
        if (! $siteId) {
            return null;
        }
        $type = $item instanceof InventoryItem
            ? $item->item_type
            : InventoryItem::query()->where('sku', $item)->value('item_type');

        return self::forTypeAtSite($siteId, $type);
    }

    public static function forTypeAtSite(?int $siteId, ?string $type): ?int
    {
        if (! $siteId) {
            return null;
        }
        $warehouses = self::activeAt($siteId);
        if ($warehouses->isEmpty()) {
            return null;
        }
        $desired = in_array($type, ['rm', 'pm'], true) ? 'Raw Material' : 'Finished Goods';
        $warehouse = $warehouses->firstWhere('warehouse_type', $desired)
            ?? $warehouses->firstWhere('warehouse_type', 'Central Distribution')
            ?? $warehouses->first();

        return (int) $warehouse->id;
    }

    /** @return Collection<int, Warehouse> */
    public static function activeAt(?int $siteId): Collection
    {
        if (! $siteId) {
            return collect();
        }

        return Warehouse::query()->where('tan90_location_id', $siteId)
            ->where('status', 'active')
            ->orderBy('code')->get();
    }
}
