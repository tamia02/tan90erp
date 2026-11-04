<?php

namespace App\Services\Forge;

use App\Models\Flow\CustomerOrder;
use App\Models\Flow\OrderLine;
use App\Models\Forge\ProductionFgMapping;
use App\Models\Forge\ProductionPlan;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesProductionService
{
    public function approvedMapping(int $siteId, int $finishedGoodId, ?int $warehouseId = null): ProductionFgMapping
    {
        $mapping = ProductionFgMapping::where('site_id', $siteId)->where('finished_good_id', $finishedGoodId)
            ->where('status', 'approved')->first();
        if (! $mapping || ($warehouseId !== null && (int) $mapping->fg_warehouse_id !== $warehouseId)) {
            throw ValidationException::withMessages(['finished_good_id' => 'No approved FG SKU and warehouse mapping for this product at the selected site.']);
        }
        $item = InventoryItem::findOrFail($mapping->inventory_fg_item_id);
        $rm = Warehouse::findOrFail($mapping->rm_warehouse_id);
        $fg = Warehouse::findOrFail($mapping->fg_warehouse_id);
        if (! $item->active || $item->item_type !== 'fg' || $rm->status !== 'active' || $fg->status !== 'active'
            || (int) $rm->tan90_location_id !== $siteId || (int) $fg->tan90_location_id !== $siteId || $item->bomLines()->doesntExist()) {
            throw ValidationException::withMessages(['finished_good_id' => 'FG mapping, recipe or warehouse is no longer active.']);
        }

        return $mapping;
    }

    /** Called after each ATP pass. Each committed plan covers only its unfulfilled production. */
    public function ensureShortagePlans(CustomerOrder $order): void
    {
        if (! $order->site_id || ! $order->fg_warehouse_id) {
            return; // Legacy orders retain their existing manual planning path.
        }
        DB::transaction(function () use ($order): void {
            $order = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            foreach ($order->lines()->lockForUpdate()->get() as $line) {
                $this->approvedMapping((int) $order->site_id, (int) $line->finished_good_id, (int) $order->fg_warehouse_id);
                $remaining = max(0, (float) $line->qty_ordered - (float) $line->qty_allocated);
                $committed = 0.0;
                $plans = ProductionPlan::where('source_order_line_id', $line->id)
                    ->whereIn('status', ['draft', 'frozen'])->lockForUpdate()->get();
                foreach ($plans as $plan) {
                    $delivered = (float) DB::table('forge_batches')->join('forge_work_orders', 'forge_work_orders.id', '=', 'forge_batches.work_order_id')
                        ->where('forge_work_orders.production_plan_id', $plan->id)->where('forge_batches.status', 'released')->sum('forge_batches.qty');
                    $active = (float) DB::table('forge_work_orders')->where('production_plan_id', $plan->id)
                        ->whereNotIn('status', ['released_to_fg', 'rejected', 'closed'])
                        ->sum('target_qty');
                    // A finished work order with reject leaves no active commitment.
                    $committed += max(0, min((float) $plan->target_qty - $delivered, $active > 0 ? $active : ($plan->workOrders()->doesntExist() ? (float) $plan->target_qty : 0)));
                }
                $shortage = round($remaining - $committed, 3);
                if ($shortage <= 0.0005) {
                    continue;
                }
                $plan = ProductionPlan::create([
                    'plan_number' => 'PP-SO-'.Str::upper(Str::ulid()),
                    'finished_good_id' => $line->finished_good_id,
                    'site_id' => $order->site_id,
                    'source_order_line_id' => $line->id,
                    'target_qty' => $shortage,
                    'uom' => $line->uom,
                    'due_date' => $order->requested_date ?? today(),
                    'status' => 'draft',
                    'created_by' => $order->created_by,
                ]);
                AuditLogger::log('Sales shortage production plan created', $order->order_number.' / '.$plan->plan_number, $plan);
                app(ProductionTaskService::class)->open('plan:'.$plan->id.':approve',
                    'Approve sales shortage plan '.$plan->plan_number, 'forge.plan.approve', (int) $order->site_id,
                    (int) $order->created_by, $plan, $order->order_number.' requires '.$shortage.' '.$line->uom.' additional FG.');
            }
        });
    }
}
