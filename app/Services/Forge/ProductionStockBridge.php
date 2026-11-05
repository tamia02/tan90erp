<?php

namespace App\Services\Forge;

use App\Models\Forge\MaterialIssue;
use App\Models\Forge\WorkOrder;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Posts only mapped Forge work orders into the site/warehouse Inventory ledger. */
class ProductionStockBridge
{
    public function validateMapping(WorkOrder $wo): void
    {
        if (! $wo->inventory_fg_item_id) {
            return;
        }
        $fg = InventoryItem::findOrFail($wo->inventory_fg_item_id);
        $rmWarehouse = Warehouse::findOrFail($wo->rm_warehouse_id);
        $fgWarehouse = Warehouse::findOrFail($wo->fg_warehouse_id);
        if ($fg->item_type !== 'fg' || ! $fg->active || $fg->unit !== $wo->uom) {
            throw ValidationException::withMessages(['inventory_fg_item_id' => 'Select an active FG SKU with the same unit as the work order.']);
        }
        if ((int) $rmWarehouse->tan90_location_id !== (int) $wo->site_id || (int) $fgWarehouse->tan90_location_id !== (int) $wo->site_id
            || $rmWarehouse->status !== 'active' || $fgWarehouse->status !== 'active'
            || $rmWarehouse->warehouse_type !== 'Raw Material' || $fgWarehouse->warehouse_type !== 'Finished Goods') {
            throw ValidationException::withMessages(['site_id' => 'Select an active Raw Material warehouse and an active Finished Goods warehouse assigned to the selected site.']);
        }
        if ($wo->reusable_pcm_item_id) {
            $reusable = InventoryItem::findOrFail($wo->reusable_pcm_item_id);
            if ($reusable->item_type !== 'rm' || ! $reusable->active) {
                throw ValidationException::withMessages(['reusable_pcm_item_id' => 'Reusable PCM must map to an active raw-material SKU.']);
            }
        }
        if ($fg->bomLines()->count() === 0) {
            throw ValidationException::withMessages(['inventory_fg_item_id' => 'Add RM/PM quantities to this Inventory FG recipe before release.']);
        }
    }

    public function reserve(WorkOrder $wo): void
    {
        $this->validateMapping($wo);
        if (! $wo->inventory_fg_item_id) {
            return;
        }
        DB::transaction(function () use ($wo): void {
            $lines = InventoryItem::findOrFail($wo->inventory_fg_item_id)->bomLines()->with('component')->get();
            InventoryItem::whereIn('id', $lines->pluck('component_item_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $item = $line->component;
                if (! $item || ! in_array($item->item_type, ['rm', 'pm'], true) || ! $item->active) {
                    throw ValidationException::withMessages(['materials' => 'The recipe contains an inactive or non-material SKU.']);
                }
                $need = round((float) $line->qty_per_unit * (float) $wo->target_qty, 3);
                $inventory = app(InventoryService::class)->atWarehouse((int) $wo->rm_warehouse_id);
                $onHand = $inventory->balances(collect([$item]))[$item->id]['final'];
                $outward = $inventory->reservedForOpenShipments(collect([$item]), (int) $wo->site_id, null, (int) $wo->rm_warehouse_id)[$item->id] ?? 0;
                $other = (float) DB::table('forge_material_reservations')->join('forge_work_orders', 'forge_work_orders.id', '=', 'forge_material_reservations.work_order_id')
                    ->where('forge_material_reservations.inventory_item_id', $item->id)->where('forge_work_orders.site_id', $wo->site_id)
                    ->where('forge_work_orders.rm_warehouse_id', $wo->rm_warehouse_id)
                    ->where('forge_material_reservations.work_order_id', '!=', $wo->id)
                    ->where('forge_work_orders.status', 'material_reserved')->sum('forge_material_reservations.qty');
                if ($need <= 0 || $need > $onHand - $outward - $other + 0.0005) {
                    throw ValidationException::withMessages(['materials' => "Insufficient {$item->safeDisplayName()} stock at the selected warehouse for this work order."]);
                }
                DB::table('forge_material_reservations')->insert([
                    'work_order_id' => $wo->id, 'inventory_item_id' => $item->id, 'qty' => $need,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function issue(WorkOrder $wo, User $actor): void
    {
        DB::transaction(function () use ($wo, $actor): void {
            $reservations = DB::table('forge_material_reservations')->where('work_order_id', $wo->id)->orderBy('inventory_item_id')->lockForUpdate()->get();
            if ($reservations->isEmpty()) {
                throw ValidationException::withMessages(['materials' => 'Reserve the mapped recipe materials before issue.']);
            }
            InventoryItem::whereIn('id', $reservations->pluck('inventory_item_id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $item = InventoryItem::findOrFail($reservation->inventory_item_id);
                $stock = app(InventoryService::class)->atWarehouse((int) $wo->rm_warehouse_id)->balances(collect([$item]))[$item->id]['final'];
                if ((float) $reservation->qty > $stock + 0.0005) {
                    throw ValidationException::withMessages(['materials' => "Insufficient {$item->safeDisplayName()} stock for issue."]);
                }
                $issue = MaterialIssue::create([
                    'work_order_id' => $wo->id, 'inventory_item_id' => $item->id,
                    'item_code' => $item->code ?: (string) $item->id,
                    'item_name' => $item->safeDisplayName(), 'qty' => $reservation->qty,
                    'uom' => $item->unit, 'movement_type' => 'issue', 'posted_by' => $actor->id, 'posted_at' => now(),
                ]);
                $move = InventoryMovement::create([
                    'item_id' => $item->id, 'type' => 'consumed', 'qty' => $reservation->qty,
                    'location_id' => $wo->site_id, 'warehouse_id' => $wo->rm_warehouse_id,
                    'source_key' => 'forge-issue:'.$issue->id, 'note' => $wo->wo_number,
                    'created_by' => $actor->id,
                ]);
                $issue->update(['inventory_movement_id' => $move->id]);
                $this->allocateFifo($issue, $item, (float) $reservation->qty, (int) $wo->rm_warehouse_id, $move->id);
            }
            DB::table('forge_material_reservations')->where('work_order_id', $wo->id)->delete();
        });
    }

    /** FIFO issue for an approved partial or top-up operator request. */
    public function issueRequested(WorkOrder $wo, int $requestId, User $actor): void
    {
        $this->validateMapping($wo);
        $lines = DB::table('forge_material_request_lines')->where('material_request_id', $requestId)->orderBy('inventory_item_id')->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['materials' => 'Material request has no lines.']);
        }
        InventoryItem::whereIn('id', $lines->pluck('inventory_item_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as $line) {
            $item = InventoryItem::findOrFail($line->inventory_item_id);
            $stock = app(InventoryService::class)->atWarehouse((int) $wo->rm_warehouse_id)->balances(collect([$item]))[$item->id]['final'];
            if ((float) $line->qty > $stock + 0.0005) {
                throw ValidationException::withMessages(['materials' => "Insufficient {$item->safeDisplayName()} stock for this partial issue."]);
            }
            $issue = MaterialIssue::create([
                'work_order_id' => $wo->id, 'material_request_line_id' => $line->id,
                'inventory_item_id' => $item->id, 'item_code' => $item->code ?: (string) $item->id,
                'item_name' => $item->safeDisplayName(), 'qty' => $line->qty, 'uom' => $item->unit,
                'movement_type' => 'issue', 'posted_by' => $actor->id, 'posted_at' => now(),
            ]);
            $movement = InventoryMovement::create([
                'item_id' => $item->id, 'type' => 'consumed', 'qty' => $line->qty,
                'location_id' => $wo->site_id, 'warehouse_id' => $wo->rm_warehouse_id,
                'source_key' => 'forge-issue:'.$issue->id, 'note' => $wo->wo_number,
                'created_by' => $actor->id,
            ]);
            $issue->update(['inventory_movement_id' => $movement->id]);
            $this->allocateFifo($issue, $item, (float) $line->qty, (int) $wo->rm_warehouse_id, $movement->id);
        }
    }

    public function receipt(WorkOrder $wo, int $batchId, float $accepted, User $actor): int
    {
        return DB::transaction(function () use ($wo, $batchId, $accepted, $actor): int {
            $this->validateMapping($wo);
            if ($accepted <= 0) {
                throw ValidationException::withMessages(['accepted_qty' => 'Accepted FG quantity must be positive.']);
            }
            $fg = InventoryMovement::create([
                'item_id' => $wo->inventory_fg_item_id, 'type' => 'produced', 'qty' => $accepted,
                'location_id' => $wo->site_id, 'warehouse_id' => $wo->fg_warehouse_id,
                'source_key' => 'forge-batch:'.$batchId, 'note' => $wo->wo_number, 'created_by' => $actor->id,
            ]);
            return $fg->id;
        });
    }

    private function allocateFifo(MaterialIssue $issue, InventoryItem $item, float $qty, int $warehouseId, int $currentMoveId): void
    {
        $currentMove = InventoryMovement::findOrFail($currentMoveId);
        $currentAt = $currentMove->created_at->toDateTimeString();
        $supplies = [];
        // Opening quantity is assigned to the site's default warehouse by InventoryService.
        if ((float) $item->opening_qty > 0 && $warehouseId === \App\Services\Inventory\WarehouseStockResolver::forTypeAtSite($issue->workOrder->site_id, $item->item_type)) {
            $supplies[] = ['type' => 'opening', 'id' => null, 'left' => (float) $item->opening_qty, 'at' => '0000-00-00 00:00:00', 'rank' => 0];
        }
        foreach (DB::table('ledger_entries')->where('sku', $item->sku)->where('bucket', 'available')->where('warehouse_id', $warehouseId)
            ->where('created_at', '<=', $currentAt)->orderBy('created_at')->orderBy('id')->get(['id', 'qty', 'created_at']) as $row) {
            $supplies[] = ['type' => 'grn', 'id' => $row->id, 'left' => (float) $row->qty, 'at' => (string) $row->created_at, 'rank' => 1];
        }
        $previousOut = 0.0;
        foreach (InventoryMovement::where('item_id', $item->id)->where('warehouse_id', $warehouseId)
            ->where('id', '!=', $currentMoveId)->orderBy('created_at')->orderBy('id')->get(['id', 'type', 'qty', 'created_at']) as $row) {
            if (! $this->isBeforeMovement($row, $currentAt, $currentMoveId)) {
                continue;
            }
            if (in_array($row->type, InventoryMovement::IN_TYPES, true)) {
                $supplies[] = ['type' => 'movement', 'id' => $row->id, 'left' => (float) $row->qty, 'at' => (string) $row->created_at, 'rank' => 2];
            } elseif (in_array($row->type, InventoryMovement::OUT_TYPES, true)) {
                $previousOut += (float) $row->qty;
            }
        }
        usort($supplies, fn ($a, $b) => [$a['at'], $a['rank'], $a['id']] <=> [$b['at'], $b['rank'], $b['id']]);
        // Older ERP stock movements have no lot link. Charge them to oldest receipts first.
        foreach ($supplies as &$supply) {
            $used = min($supply['left'], $previousOut);
            $supply['left'] -= $used;
            $previousOut -= $used;
            if ($previousOut <= 0) {
                break;
            }
        }
        unset($supply);
        foreach ($supplies as $supply) {
            $taken = min($qty, $supply['left']);
            if ($taken <= 0) {
                continue;
            }
            DB::table('forge_material_fifo_allocations')->insert([
                'material_issue_id' => $issue->id, 'source_type' => $supply['type'], 'source_id' => $supply['id'],
                'qty' => round($taken, 3), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $qty -= $taken;
            if ($qty <= 0.0005) {
                break;
            }
        }
        if ($qty > 0.0005) {
            throw ValidationException::withMessages(['materials' => 'FIFO receipt history cannot cover the requested issue; reconcile legacy stock first.']);
        }
    }

    private function isBeforeMovement(object $movement, string $currentAt, int $currentMoveId): bool
    {
        $movementAt = substr((string) $movement->created_at, 0, 19);

        return $movementAt < $currentAt || ($movementAt === $currentAt && (int) $movement->id < $currentMoveId);
    }
}
