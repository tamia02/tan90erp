<?php

namespace App\Http\Controllers\Inventory;

use App\Models\AuditLogEntry;
use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\PurchaseOrderLine;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ItemHistoryController
{
    public function show(InventoryItem $inventoryItem, InventoryService $inventory): View
    {
        $skuMaster = SkuMaster::query()
            ->where(function ($query) use ($inventoryItem): void {
                if (filled($inventoryItem->code)) {
                    $query->where('product_code', $inventoryItem->code);
                }
                $query->orWhere('sku', $inventoryItem->sku);
            })
            ->first();
        $supplier = $skuMaster?->vendor_name
            ? VendorMaster::query()->where('vendor_name', $skuMaster->vendor_name)->first()
            : null;
        $itemSet = collect([$inventoryItem]);
        $locations = \App\Support\SiteLocation::visibleLocations();
        $requestedLocationId = request()->integer('location_id') ?: null;
        if ($requestedLocationId !== null) {
            abort_unless($locations->contains(fn ($location) => (int) $location->id === $requestedLocationId), 404);
            $locations = $locations->where('id', $requestedLocationId)->values();
        }
        $warehouses = $locations->flatMap(fn ($location) => \App\Services\Inventory\WarehouseStockResolver::activeAt((int) $location->id)->map(fn ($warehouse) => $warehouse->setAttribute('location_name', $location->name)))
            ->sortBy('code')->values();
        $requestedWarehouseId = request()->integer('warehouse_id') ?: null;
        if ($requestedWarehouseId !== null) {
            abort_unless($warehouses->contains(fn ($warehouse) => (int) $warehouse->id === $requestedWarehouseId), 404);
            $warehouses = $warehouses->where('id', $requestedWarehouseId)->values();
        }
        $locationBalances = [];
        foreach ($warehouses as $warehouse) {
            $siteInventory = $inventory->atWarehouse((int) $warehouse->id);
            $siteBalance = $siteInventory->balances($itemSet)[$inventoryItem->id];
            $siteBalance['reserved'] = $siteInventory->reservedForOpenShipments($itemSet, (int) $warehouse->tan90_location_id, null, (int) $warehouse->id)[$inventoryItem->id] ?? 0.0;
            $siteBalance['available'] = round(max(0, $siteBalance['final'] - $siteBalance['reserved']), 3);
            $locationBalances[(int) $warehouse->id] = ['warehouse' => $warehouse, 'balance' => $siteBalance];
        }

        $selectedInventory = $requestedWarehouseId !== null
            ? $inventory->atWarehouse($requestedWarehouseId)
            : ($requestedLocationId !== null ? $inventory->atSite($requestedLocationId) : $inventory);
        $balances = $selectedInventory->balances($itemSet)[$inventoryItem->id];
        $balances['reserved'] = $selectedInventory->reservedForOpenShipments($itemSet, $requestedLocationId ?? $inventory->readSite(), null, $requestedWarehouseId)[$inventoryItem->id] ?? 0.0;
        $balances['available'] = round(max(0, $balances['final'] - $balances['reserved']), 3);
        $history = $selectedInventory->history($inventoryItem);
        $warehouseCodes = $warehouses->keyBy('id')->map(fn ($warehouse) => $warehouse->code);
        $history = $history->map(function (array $movement) use ($warehouseCodes): array {
            $movement['warehouse'] = $warehouseCodes->get($movement['warehouse_id'] ?? null, 'Unassigned / legacy');

            return $movement;
        });
        if ((float) $inventoryItem->opening_qty !== 0.0
            && ($requestedWarehouseId === null
                ? ($requestedLocationId === null || $requestedLocationId === \App\Support\SiteLocation::defaultId())
                : \App\Services\Inventory\WarehouseStockResolver::forItemAtSite($requestedLocationId ?: (int) $warehouses->first()?->tan90_location_id, $inventoryItem) === $requestedWarehouseId)) {
            $history->push([
                'at' => $inventoryItem->created_at,
                'label' => 'Opening balance recorded when the item was added',
                'qty' => (float) $inventoryItem->opening_qty,
                'kind' => 'opening',
                'location_id' => \App\Support\SiteLocation::defaultId(),
                'warehouse' => $warehouseCodes->get(\App\Services\Inventory\WarehouseStockResolver::forItemAtSite(\App\Support\SiteLocation::defaultId(), $inventoryItem), 'Main warehouse'),
            ]);
            $history = $history->sortByDesc('at')->values();
        }
        $historyKind = (string) request()->query('kind', '');
        if (in_array($historyKind, ['received', 'produced', 'consumed', 'wastage', 'dispatched', 'adjusted'], true)) {
            $history = $history->where('kind', $historyKind)->values();
        } else {
            $historyKind = '';
        }

        $purchaseProducts = array_values(array_unique(array_filter([
            $inventoryItem->sku,
            $inventoryItem->code,
            $skuMaster?->product_code,
        ])));
        $purchaseLines = $purchaseProducts === []
            ? collect()
            : PurchaseOrderLine::query()
                ->with('purchaseOrder')
                ->whereHas('purchaseOrder')
                ->whereIn('product', $purchaseProducts)
                ->orderByDesc('purchase_order_lines.created_at')
                ->get();
        $purchaseOrders = $purchaseLines->pluck('purchaseOrder')->filter()->unique('id');
        $receiptStatusByPo = $purchaseOrders->mapWithKeys(function ($po): array {
            $gates = $po->gateEntries()->with(['grnRecord', 'qcResult'])->get();
            $posted = $gates->filter(fn ($gate) => $gate->grnRecord?->posted);
            $status = match (true) {
                $posted->isNotEmpty() && $posted->every(fn ($gate) => $gate->putaway_completed_at !== null) => 'Put away done',
                $posted->isNotEmpty() => 'GRN posted · Putaway pending',
                $gates->isNotEmpty() => \App\Support\GateStatusLabels::label($gates->sortByDesc('created_at')->first()->status),
                default => $po->status ?: '—',
            };

            return [$po->id => $status];
        });

        $sourceDetails = collect();
        if ($skuMaster && Schema::hasTable('tan90_master_source_rows')) {
            $allowedFields = [
                'Brand', 'Manufacturer', 'HSN/SAC', 'Taxable', 'Taxability Type', 'Exemption Reason',
                'Intra State Tax Name', 'Intra State Tax Rate', 'Intra State Tax Type',
                'Inter State Tax Name', 'Inter State Tax Rate', 'Inter State Tax Type',
                'Usage unit', 'Unit', 'Unit Name', 'Status', 'Reorder Point', 'Opening Stock', 'Description', 'Item Description',
                'Mapped Item Name', 'Mapped Item SKU', 'Mapped Quantity',
            ];
            $sourceRows = DB::table('tan90_master_source_rows')
                ->whereIn('source_file', ['Items.xlsx', 'Composite_Items.xlsx'])
                ->where('canonical_type', 'sku')
                ->where('canonical_id', $skuMaster->id)
                ->orderBy('source_file')
                ->orderBy('source_row_number')
                ->get(['source_file', 'source_row_number', 'payload']);

            foreach ($sourceRows as $sourceRow) {
                try {
                    $payload = json_decode(Crypt::decryptString($sourceRow->payload), true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }

                $fields = collect($payload)
                    ->only($allowedFields)
                    ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
                    ->when(! InventoryItem::canSeeSkuName(auth()->user()), fn ($values) => $values->except(['Mapped Item SKU']))
                    ->all();
                if ($fields !== []) {
                    $sourceDetails->push([
                        'file' => $sourceRow->source_file,
                        'row' => $sourceRow->source_row_number,
                        'fields' => $fields,
                    ]);
                }
            }
        }

        $recipeLines = Schema::hasTable('inventory_bom_lines')
            ? $inventoryItem->bomLines()->with('component')->get()
            : collect();
        $usedIn = Schema::hasTable('inventory_bom_lines')
            ? DB::table('inventory_bom_lines')
                ->join('inventory_items as finished_goods', 'finished_goods.id', '=', 'inventory_bom_lines.fg_item_id')
                ->where('inventory_bom_lines.component_item_id', $inventoryItem->id)
                ->orderBy('finished_goods.name')
                ->get(['finished_goods.name', 'finished_goods.sku', 'inventory_bom_lines.qty_per_unit'])
            : collect();
        $audit = Schema::hasTable('audit_log_entries')
            ? AuditLogEntry::query()
                ->where('subject_type', InventoryItem::class)
                ->where('subject_id', $inventoryItem->id)
                ->latest()
                ->get()
            : collect();

        return view('inventory.item-history', [
            'item' => $inventoryItem,
            'skuMaster' => $skuMaster,
            'supplier' => $supplier,
            'balances' => $balances,
            'locationBalances' => $locationBalances,
            'history' => $history,
            'historyKind' => $historyKind,
            'purchaseLines' => $purchaseLines,
            'receiptStatusByPo' => $receiptStatusByPo,
            'sourceDetails' => $sourceDetails,
            'recipeLines' => $recipeLines,
            'usedIn' => $usedIn,
            'audit' => $audit,
            'canSeePrices' => auth()->user()?->isAdmin() ?? false,
            'canSeeSku' => InventoryItem::canSeeSkuName(auth()->user()),
        ]);
    }
}
