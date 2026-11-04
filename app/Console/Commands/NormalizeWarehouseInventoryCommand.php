<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NormalizeWarehouseInventoryCommand extends Command
{
    protected $signature = 'inventory:normalize-warehouse-stock {--target=10000 : Target on-hand quantity for every SKU in every active warehouse} {--dry-run : Report the planned change without writing stock}';

    protected $description = 'Set each inventory SKU balance to an exact quantity in every active warehouse, with auditable movements.';

    public function handle(InventoryService $inventory): int
    {
        $target = filter_var($this->option('target'), FILTER_VALIDATE_FLOAT);
        if ($target === false || $target < 0) {
            $this->error('Target must be a non-negative number.');

            return self::FAILURE;
        }

        $inventory->syncFromMasters();
        $warehouses = Warehouse::query()->where('status', 'active')->orderBy('code')->get();
        $items = InventoryItem::query()->orderBy('id')->get();
        if ($warehouses->isEmpty() || $items->isEmpty()) {
            $this->error('No active warehouses or inventory items were found; no stock was changed.');

            return self::FAILURE;
        }

        $actor = User::query()->where('role', Role::Admin->value)->first();
        if (! $actor) {
            $this->error('A Super Admin account is required to attribute the stock audit movements. No stock was changed.');

            return self::FAILURE;
        }

        $preflight = $this->buildPlan($warehouses, $items, (float) $target, $inventory);
        if ($preflight['blocked'] !== []) {
            $this->error('No stock was changed. Open shipment reservations exceed the target in: '.implode(', ', $preflight['blocked']));

            return self::FAILURE;
        }
        $this->showPlan($preflight['summary'], (int) $items->count(), (float) $target);
        if ($this->option('dry-run')) {
            $this->info('Dry run only. No inventory movements were written.');

            return self::SUCCESS;
        }

        try {
            $summary = DB::transaction(function () use ($warehouses, $items, $target, $inventory, $actor): array {
                InventoryItem::query()->whereIn('id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);
                $plan = $this->buildPlan($warehouses, $items, (float) $target, $inventory);
                if ($plan['blocked'] !== []) {
                    throw new RuntimeException('Open shipment reservations changed during the stock update; transaction cancelled.');
                }

                $now = now();
                $rows = [];
                foreach ($plan['summary'] as $warehouseId => $totals) {
                    foreach ($plan['changes'][$warehouseId] as $change) {
                        $rows[] = [
                            'item_id' => $change['item_id'],
                            'type' => $change['delta'] > 0 ? 'adjust_in' : 'adjust_out',
                            'qty' => abs($change['delta']),
                            'production_id' => null,
                            'dispatch_id' => null,
                            'note' => 'Authorized stock normalization to '.number_format((float) $target, 3, '.', '').' per SKU and warehouse',
                            'created_by' => $actor->id,
                            'location_id' => $totals['location_id'],
                            'warehouse_id' => (int) $warehouseId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('inventory_movements')->insert($chunk);
                }

                $detail = collect($plan['summary'])->map(fn ($totals) => "{$totals['code']}: {$totals['changed']} SKU balances; +".number_format($totals['increase'], 3, '.', '').' / −'.number_format($totals['decrease'], 3, '.', ''))->implode('; ');
                AuditLogger::log('Warehouse inventory normalized', "Target {$target} units for {$items->count()} SKUs in {$warehouses->count()} warehouses. {$detail}");

                return $plan['summary'];
            }, attempts: 3);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('No stock change was committed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->showPlan($summary, (int) $items->count(), (float) $target);
        $this->info('Warehouse balances were normalized and logged as stock movements.');

        return self::SUCCESS;
    }

    /** @return array{summary: array<int, array{code: string, location_id: int, changed: int, increase: float, decrease: float}>, changes: array<int, array<int, array{item_id: int, delta: float}>>, blocked: array<int, string>} */
    private function buildPlan($warehouses, $items, float $target, InventoryService $inventory): array
    {
        $summary = [];
        $changes = [];
        $blocked = [];
        foreach ($warehouses as $warehouse) {
            $atWarehouse = $inventory->atWarehouse((int) $warehouse->id);
            $balances = $atWarehouse->balances($items);
            $reserved = $atWarehouse->reservedForOpenShipments($items, (int) $warehouse->tan90_location_id, null, (int) $warehouse->id);
            $changes[$warehouse->id] = [];
            $increase = 0.0;
            $decrease = 0.0;
            foreach ($items as $item) {
                $current = round((float) ($balances[$item->id]['final'] ?? 0), 3);
                $held = round((float) ($reserved[$item->id] ?? 0), 3);
                if ($held > $target + 0.0005) {
                    $blocked[] = $warehouse->code.' / item '.$item->id.' ('.$held.' reserved)';
                    continue;
                }
                $delta = round($target - $current, 3);
                if (abs($delta) < 0.0005) {
                    continue;
                }
                $changes[$warehouse->id][] = ['item_id' => (int) $item->id, 'delta' => $delta];
                $delta > 0 ? $increase += $delta : $decrease += abs($delta);
            }
            $summary[$warehouse->id] = [
                'code' => (string) $warehouse->code,
                'location_id' => (int) $warehouse->tan90_location_id,
                'changed' => count($changes[$warehouse->id]),
                'increase' => round($increase, 3),
                'decrease' => round($decrease, 3),
            ];
        }

        return compact('summary', 'changes', 'blocked');
    }

    private function showPlan(array $summary, int $skuCount, float $target): void
    {
        $this->table(['Warehouse Code', 'SKU count', 'Target each', 'Changed', 'Increase', 'Decrease'], collect($summary)->map(fn ($row) => [
            $row['code'], $skuCount, number_format($target, 3, '.', ''), $row['changed'], number_format($row['increase'], 3, '.', ''), number_format($row['decrease'], 3, '.', ''),
        ])->all());
    }
}
