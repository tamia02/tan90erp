<?php

namespace App\Services\Zoho;

use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Models\SkuMaster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Zoho composite items carry their recipe (components + qty per 1 unit). This
// copies those recipes onto the Inventory module's Finished Goods so "Create
// Bundle" uses exactly what Zoho uses. GET-only; run the item pull first so
// every composite and component already exists in SKU Master.
// Only recipes whose composite changed in Zoho since the last pull are fetched
// (one detail call each), unless --force.
class ZohoRecipePullService
{
    public function __construct(private ZohoReadClient $zoho) {}

    /** @return array<string, int> */
    public function pull(bool $dryRun = false, bool $force = false, int $pauseMs = 700): array
    {
        if (! $this->zoho->configured()) {
            throw new RuntimeException('Zoho pull is not configured (organization id / refresh token / client).');
        }
        $this->zoho->pauseMs = $pauseMs;

        $stats = ['composites' => 0, 'recipes_updated' => 0, 'unchanged' => 0, 'lines_written' => 0,
            'missing_finished_good' => 0, 'missing_components' => 0, 'would_fetch' => 0];

        foreach ($this->zoho->all('/compositeitems', 'composite_items') as $composite) {
            $stats['composites']++;
            $fgSku = SkuMaster::where('zoho_item_id', (string) $composite['composite_item_id'])->first();
            if (! $fgSku) {
                $stats['missing_finished_good']++;

                continue;
            }

            $modified = (string) ($composite['last_modified_time'] ?? '');
            if (! $force && $modified !== '' && $fgSku->zoho_recipe_modified_at === $modified) {
                $stats['unchanged']++;

                continue;
            }
            if ($dryRun) {
                $stats['would_fetch']++;

                continue;
            }

            $detail = $this->zoho->get('/compositeitems/'.$composite['composite_item_id'])['composite_item'] ?? [];
            $components = $detail['composite_component_items'] ?? $detail['mapped_items'] ?? [];

            $fg = $this->inventoryItemFor($fgSku, 'fg');
            $lines = [];
            foreach ($components as $component) {
                $componentSku = SkuMaster::where('zoho_item_id', (string) ($component['item_id'] ?? ''))->first();
                $qty = (float) ($component['quantity'] ?? 0);
                if (! $componentSku || $qty <= 0) {
                    $stats['missing_components']++;

                    continue;
                }
                $componentItem = $this->inventoryItemFor($componentSku, 'rm');
                if ($componentItem->id !== $fg->id) {
                    $lines[$componentItem->id] = ($lines[$componentItem->id] ?? 0) + $qty;
                }
            }

            DB::transaction(function () use ($fg, $lines, $fgSku, $modified) {
                $fg->bomLines()->delete();
                foreach ($lines as $componentId => $qty) {
                    InventoryBomLine::create(['fg_item_id' => $fg->id, 'component_item_id' => $componentId, 'qty_per_unit' => $qty]);
                }
                SkuMaster::withoutEvents(fn () => $fgSku->forceFill(['zoho_recipe_modified_at' => $modified])->save());
            });

            $stats['recipes_updated']++;
            $stats['lines_written'] += count($lines);
        }

        $stats['api_calls'] = $this->zoho->calls;

        return $stats;
    }

    private function inventoryItemFor(SkuMaster $sku, string $fallbackType): InventoryItem
    {
        return InventoryItem::whereRaw('LOWER(sku) = ?', [mb_strtolower($sku->sku)])->first()
            ?? InventoryItem::create(['sku' => $sku->sku, 'item_type' => $fallbackType, 'unit' => $sku->unit ?: 'PCS',
                'opening_qty' => 0, 'active' => (bool) $sku->active]);
    }
}
