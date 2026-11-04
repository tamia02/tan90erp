<?php

namespace App\Services\Flow;

use App\Models\Flow\Allocation;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\Delivery;
use App\Models\Flow\HandlingUnit;
use App\Models\Flow\InventoryLot;
use App\Models\Flow\InventoryMovement;
use App\Models\Flow\OrderLine;
use App\Models\Flow\PickingWave;
use App\Models\Flow\PickTask;
use App\Models\Flow\Shipment;
use App\Models\Flow\TemperatureEvent;
use App\Models\Forge\Batch;
use App\Models\Inventory\InventoryItem as StockItem;
use App\Models\Inventory\InventoryMovement as StockMovement;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\Tan90\MasterData\Bin;
use App\Services\Inventory\InventoryService;
use App\Services\Forge\SalesProductionService;
use App\Services\Forge\ProductionTaskService;
use App\Support\SiteLocation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Owns Flow's order-to-delivery state machine (ARCHITECTURE.md #5) and the
// append-only inventory ledger (ARCHITECTURE.md #4) - flow_inventory_lots
// balances are only ever changed alongside a flow_inventory_movements row,
// never edited bare. Allocation only considers InventoryLot::isAtpEligible().
class FulfillmentService
{
    public function receiveFinishedGoods(Batch $batch, array $data, User $actor): InventoryLot
    {
        if ($batch->status !== 'released') {
            throw ValidationException::withMessages(['status' => 'Only a released Forge batch can be received into Flow.']);
        }

        if (InventoryLot::where('forge_batch_id', $batch->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'This batch has already been received.']);
        }

        $lot = DB::transaction(function () use ($batch, $data, $actor) {
            $wo = $batch->workOrder;
            $managed = (int) ($wo->workflow_version ?? 1) >= 2;
            $warehouse = $wo->inventory_fg_item_id ? Warehouse::findOrFail($wo->fg_warehouse_id) : null;
            $lot = InventoryLot::create([
                'finished_good_id' => $batch->workOrder->finished_good_id,
                'forge_batch_id' => $batch->id,
                'lot_number' => $batch->batch_number,
                'warehouse' => $warehouse?->name ?? $data['warehouse'] ?? 'Bhiwandi FG Warehouse',
                'inventory_item_id' => $wo->inventory_fg_item_id,
                'location_id' => $wo->site_id,
                'warehouse_id' => $wo->fg_warehouse_id,
                'zone' => $managed ? null : ($data['zone'] ?? null),
                'bin' => $managed ? null : ($data['bin'] ?? null),
                'putaway_started_at' => $managed ? null : (! empty($data['bin']) ? ($data['putaway_started_at'] ?? null) : null),
                'putaway_ended_at' => $managed ? null : (! empty($data['bin']) ? ($data['putaway_ended_at'] ?? null) : null),
                'putaway_manpower' => $managed ? null : (! empty($data['bin']) ? ($data['putaway_manpower'] ?? null) : null),
                'putaway_by' => $managed ? null : (! empty($data['bin']) ? $actor->id : null),
                'qty_received' => $batch->qty,
                'qty_available' => $batch->qty,
                'uom' => $batch->uom,
                'quality_status' => 'released',
                'expiry_date' => $batch->shelf_life_date,
                'status' => $managed ? 'staged' : ($data['bin'] ?? null ? 'available' : 'staged'),
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);

            $this->postMovement($lot, 'fg_receipt', $lot->qty_received, $actor, Batch::class, $batch->id, $data);

            return $lot;
        });

        AuditLogger::log('FG received into Flow', $lot->lot_number, $lot);
        if ((int) ($batch->workOrder?->workflow_version ?? 1) >= 2) {
            app(ProductionTaskService::class)->done('batch:'.$batch->id.':receive', $actor->id);
            app(ProductionTaskService::class)->open('lot:'.$lot->id.':putaway', 'Put FG lot '.$lot->lot_number.' in a master bin',
                'flow.inventory.putaway', (int) $lot->location_id, $actor->id, $lot);
        }

        return $lot;
    }

    public function putaway(InventoryLot $lot, array $data, User $actor): InventoryLot
    {
        DB::transaction(function () use ($lot, $data, $actor): void {
            $lot = InventoryLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if ($lot->status !== 'staged') {
                throw ValidationException::withMessages(['status' => 'Only a staged lot can be put away.']);
            }
            $managed = (int) ($lot->forgeBatch?->workOrder?->workflow_version ?? 1) >= 2;
            if ($managed) {
                $bin = Bin::with('shelf.rack.zone')->whereKey($data['bin_id'] ?? 0)
                    ->where('status', 'active')->where('approval_status', 'approved')->first();
                if (! $bin || (int) $bin->shelf?->rack?->zone?->tan90_warehouse_id !== (int) $lot->warehouse_id) {
                    throw ValidationException::withMessages(['bin_id' => 'Choose an active master bin in the mapped FG warehouse.']);
                }
                $data['bin'] = $bin->code;
                $data['zone'] = $bin->shelf->rack->zone->code;
            }
            $lot->update([
                'zone' => $data['zone'], 'bin' => $data['bin'], 'bin_id' => $managed ? $data['bin_id'] : null, 'status' => 'available',
                'putaway_started_at' => $data['putaway_started_at'], 'putaway_ended_at' => $data['putaway_ended_at'],
                'putaway_manpower' => $data['putaway_manpower'], 'putaway_by' => $actor->id,
            ]);
            $this->postMovement($lot, 'putaway', $lot->qty_available, $actor, null, null, [
                'zone' => $data['zone'], 'bin' => $data['bin'], 'started_at' => $data['putaway_started_at'],
                'ended_at' => $data['putaway_ended_at'], 'manpower' => $data['putaway_manpower'],
            ]);
            if ($managed) {
                app(ProductionTaskService::class)->done('lot:'.$lot->id.':putaway', $actor->id);
                $orders = CustomerOrder::where('site_id', $lot->location_id)->where('fg_warehouse_id', $lot->warehouse_id)
                    ->whereIn('status', ['released', 'atp_partial'])
                    ->whereHas('lines', fn ($q) => $q->where('inventory_fg_item_id', $lot->inventory_item_id))
                    ->orderByRaw('requested_date IS NULL, requested_date ASC')->orderBy('id')->get();
                foreach ($orders as $order) {
                    $this->runAtpAndAllocate($order);
                    app(SalesProductionService::class)->ensureShortagePlans($order->fresh());
                    if ($order->fresh()->status === 'allocated') {
                        app(ProductionTaskService::class)->open('order:'.$order->id.':logistics', 'Pick, pack and dispatch '.$order->order_number,
                            'flow.dispatch.manage', (int) $order->site_id, $actor->id, $order);
                    }
                }
            }
        });

        AuditLogger::log('FG lot put away', $lot->lot_number.' -> '.$lot->fresh()->bin, $lot);

        return $lot;
    }

    public function createOrder(array $data, User $actor): CustomerOrder
    {
        $data['min_shelf_life_days'] ??= 0;

        $order = CustomerOrder::create($data + [
            'order_number' => 'SO-'.now()->format('Y').'-'.str_pad((string) (CustomerOrder::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        AuditLogger::log('Customer order created', $order->order_number, $order);

        return $order;
    }

    public function addLine(CustomerOrder $order, array $data): OrderLine
    {
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Lines can only be added to a draft order.']);
        }

        return $order->lines()->create($data);
    }

    public function validateOrder(CustomerOrder $order): CustomerOrder
    {
        $this->assertStatus($order, ['draft']);

        if ($order->lines()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'Order needs at least one line before it can be validated.']);
        }

        $order->update(['status' => 'validated']);
        AuditLogger::log('Order validated', $order->order_number, $order);

        return $order;
    }

    public function release(CustomerOrder $order): CustomerOrder
    {
        $this->assertStatus($order, ['validated']);
        $order->update(['status' => 'released']);
        AuditLogger::log('Order released', $order->order_number, $order);

        $allocated = $this->runAtpAndAllocate($order);
        app(SalesProductionService::class)->ensureShortagePlans($allocated);
        if ($allocated->site_id && $allocated->status === 'allocated') {
            app(ProductionTaskService::class)->open('order:'.$allocated->id.':logistics', 'Pick, pack and dispatch '.$allocated->order_number,
                'flow.dispatch.manage', (int) $allocated->site_id, (int) $allocated->created_by, $allocated);
        }

        return $allocated;
    }

    /**
     * FEFO allocation: sort ATP-eligible lots by earliest expiry, reserve
     * lot-by-lot per FUNCTIONAL-FLOWS.md #3. Publishes atp_confirmed when
     * every line is fully covered, atp_partial otherwise (never silently
     * drops the shortage).
     */
    public function runAtpAndAllocate(CustomerOrder $order): CustomerOrder
    {
        $fullyCovered = true;

        DB::transaction(function () use ($order, &$fullyCovered) {
            foreach ($order->lines as $line) {
                $remaining = $line->outstandingQty();
                if ($remaining <= 0) {
                    continue;
                }

                $pcmMapped = $order->site_id !== null && DB::table('production_fg_mappings')
                    ->where('site_id', $order->site_id)
                    ->where('finished_good_id', $line->finished_good_id)
                    ->where('status', 'approved')
                    ->whereNotNull('reusable_pcm_item_id')
                    ->exists();
                $lotQuery = InventoryLot::where('finished_good_id', $line->finished_good_id)
                    ->when($order->site_id, fn ($q) => $q->where('location_id', $order->site_id),
                        fn ($q) => $q->when(SiteLocation::forUser(auth()->user()), fn ($q, $site) => $q->where('location_id', $site)))
                    ->when($order->fg_warehouse_id, fn ($q) => $q->where('warehouse_id', $order->fg_warehouse_id))
                    ->when($line->inventory_fg_item_id, fn ($q) => $q->where('inventory_item_id', $line->inventory_fg_item_id))
                    ->where('status', 'available')
                    ->where('quality_status', 'released')
                    ->where('qty_available', '>', 0)
                    ->when($order->min_shelf_life_days > 0, fn ($q) => $q->where('expiry_date', '>=', now()->addDays($order->min_shelf_life_days)));
                if ($pcmMapped) {
                    $lotQuery->orderBy('received_at')->orderBy('id');
                } else {
                    $lotQuery->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('received_at')->orderBy('id');
                }
                $lots = $lotQuery->lockForUpdate()->get()->filter->isAtpEligible();

                foreach ($lots as $lot) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (float) $lot->qty_available);

                    Allocation::create(['order_line_id' => $line->id, 'inventory_lot_id' => $lot->id, 'qty' => $take, 'status' => 'reserved']);
                    $lot->decrement('qty_available', $take);
                    $lot->increment('qty_allocated', $take);
                    $this->postMovement($lot, 'allocation', $take, null, OrderLine::class, $line->id);

                    $line->increment('qty_allocated', $take);
                    $remaining -= $take;
                }

                if ($remaining > 0.0001) {
                    $fullyCovered = false;
                }
            }

            $order->update(['status' => $fullyCovered ? 'atp_confirmed' : 'atp_partial']);
        });

        $order->refresh();
        if (($fullyCovered || ! $order->site_id) && $order->allocations()->exists()) {
            $order->update(['status' => 'allocated']);
        }

        AuditLogger::log('ATP run and FIFO/FEFO allocation complete', $order->order_number.' -> '.$order->status, $order);

        return $order;
    }

    public function buildWave(array $orderLineIds, string $warehouse, User $actor): PickingWave
    {
        $lines = OrderLine::whereIn('id', $orderLineIds)->with('order')->get();
        foreach ($lines->pluck('order')->unique('id') as $order) {
            if ($order->site_id) {
                SiteLocation::assertCanAccessSiteRecord($actor, (int) $order->site_id);
                if ($order->status !== 'allocated' || $order->lines()->whereNotIn('id', $orderLineIds)->exists()
                    || $order->lines()->whereRaw('qty_allocated < qty_ordered')->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Select every line of a fully allocated sales order before picking.']);
                }
                $valid = Allocation::whereIn('order_line_id', $order->lines()->pluck('id'))->where('status', 'reserved')
                    ->whereHas('inventoryLot', fn ($q) => $q->where('location_id', $order->site_id)->where('warehouse_id', $order->fg_warehouse_id))->exists();
                if (! $valid) {
                    throw ValidationException::withMessages(['warehouse' => 'No reserved mapped FG stock at this order site and warehouse.']);
                }
            }
        }
        $wave = PickingWave::create([
            'wave_number' => 'WV-'.now()->format('Y').'-'.str_pad((string) (PickingWave::count() + 1), 4, '0', STR_PAD_LEFT),
            'warehouse' => $warehouse,
            'status' => 'draft',
        ]);

        DB::transaction(function () use ($wave, $orderLineIds) {
            $allocations = Allocation::whereIn('order_line_id', $orderLineIds)->where('status', 'reserved')->get();
            foreach ($allocations as $allocation) {
                PickTask::create([
                    'wave_id' => $wave->id,
                    'allocation_id' => $allocation->id,
                    'qty_to_pick' => $allocation->qty,
                    'status' => 'pending',
                ]);
            }
        });

        AuditLogger::log('Wave built', $wave->wave_number, $wave);

        return $wave;
    }

    public function publishWave(PickingWave $wave, User $actor): PickingWave
    {
        if ($wave->pickTasks()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'Wave has no pick tasks.']);
        }

        $wave->update(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()]);

        $orderIds = $wave->pickTasks->pluck('allocation.orderLine.customer_order_id')->unique();
        CustomerOrder::whereIn('id', $orderIds)->where('status', 'allocated')->update(['status' => 'waved']);

        AuditLogger::log('Wave published', $wave->wave_number, $wave);

        return $wave;
    }

    public function confirmPick(PickTask $task, float $qtyPicked, User $actor): PickTask
    {
        if ($task->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Pick task already actioned.']);
        }

        DB::transaction(function () use ($task, $qtyPicked, $actor) {
            $short = $qtyPicked < (float) $task->qty_to_pick;
            $task->update([
                'qty_picked' => $qtyPicked, 'status' => $short ? 'short' : 'picked',
                'picked_by' => $actor->id, 'picked_at' => now(),
            ]);

            $allocation = $task->allocation;
            $lot = $allocation->inventoryLot;
            $lot->decrement('qty_allocated', $qtyPicked);
            $lot->increment('qty_picked', $qtyPicked);
            if ($short && $allocation->orderLine->order->site_id) {
                $shortQty = (float) $task->qty_to_pick - $qtyPicked;
                $lot->decrement('qty_allocated', $shortQty);
                $lot->increment('qty_available', $shortQty);
                $lot->update(['status' => 'blocked']);
                $this->postMovement($lot, 'deallocation', $shortQty, $actor, PickTask::class, $task->id);
                $allocation->orderLine->decrement('qty_allocated', $shortQty);
                $allocation->update(['qty' => $qtyPicked]);
            }
            $this->postMovement($lot, 'pick', $qtyPicked, $actor, PickTask::class, $task->id);

            $line = $allocation->orderLine;
            $line->increment('qty_picked', $qtyPicked);
            $allocation->update(['status' => $qtyPicked > 0 ? 'picked' : 'deallocated']);

            $order = $line->order;
            if ($order->status === 'waved') {
                $order->update(['status' => 'picking']);
            }

            $orderTaskStatuses = PickTask::whereHas('allocation.orderLine', fn ($q) => $q->where('customer_order_id', $order->id))->pluck('status');
            if ($orderTaskStatuses->every(fn ($s) => in_array($s, ['picked', 'short'], true))) {
                $allPicked = $order->lines()->whereRaw('qty_picked < qty_ordered')->doesntExist();
                $order->update(['status' => $order->site_id && ! $allPicked ? 'atp_partial' : 'picked']);
            }
        });

        AuditLogger::log('Pick confirmed', 'task #'.$task->id.' qty '.$qtyPicked, $task);

        $order = $task->allocation->orderLine->order->fresh();
        if ($order->site_id && $order->status === 'atp_partial') {
            $this->runAtpAndAllocate($order);
            app(SalesProductionService::class)->ensureShortagePlans($order->fresh());
        }

        return $task;
    }

    public function packIntoHandlingUnit(CustomerOrder $order, array $data, User $actor): HandlingUnit
    {
        if ($order->site_id) {
            SiteLocation::assertCanAccessSiteRecord($actor, (int) $order->site_id);
            if (! in_array($order->status, ['picked', 'packing'], true) || $order->lines()->whereRaw('qty_picked < qty_ordered')->exists()) {
                throw ValidationException::withMessages(['status' => 'All order lines must be fully picked before packing.']);
            }
        }
        return DB::transaction(function () use ($order, $data, $actor): HandlingUnit {
        $hu = HandlingUnit::create([
            'customer_order_id' => $order->id,
            'hu_number' => 'HU-'.now()->format('Y').'-'.str_pad((string) (HandlingUnit::count() + 1), 5, '0', STR_PAD_LEFT),
            'weight_kg' => $data['weight_kg'] ?? null,
            'status' => 'packed',
        ]);

        if ($order->site_id) {
            $packedAny = false;
            foreach ($data['lines'] ?? [] as $input) {
                $qty = (float) ($input['qty'] ?? 0);
                if ($qty <= 0) continue;
                $line = $order->lines()->whereKey($input['order_line_id'])->lockForUpdate()->firstOrFail();
                if ((float) $line->qty_packed + $qty > (float) $line->qty_picked + 0.0005) {
                    throw ValidationException::withMessages(['lines' => 'Carton quantity exceeds picked quantity for an order line.']);
                }
                DB::table('flow_handling_unit_lines')->insert([
                    'handling_unit_id' => $hu->id, 'order_line_id' => $line->id,
                    'qty' => $qty, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $line->increment('qty_packed', $qty);
                $packedAny = true;
            }
            if (! $packedAny) {
                throw ValidationException::withMessages(['lines' => 'Enter packed quantity for at least one order line.']);
            }
        } else {
            $order->lines()->increment('qty_packed', $data['qty_packed'] ?? 0);
        }
        $order->update(['status' => 'packing']);

        AuditLogger::log('Handling unit packed', $hu->hu_number, $hu);

        return $hu;
        });
    }

    public function sealHandlingUnit(HandlingUnit $hu): HandlingUnit
    {
        $this->assertHuStatus($hu, ['packed']);
        $hu->update(['status' => 'sealed', 'sealed_at' => now()]);

        if ($hu->order->handlingUnits()->where('status', '!=', 'sealed')->doesntExist()
            && (! $hu->order->site_id || $hu->order->lines()->whereRaw('qty_packed < qty_ordered')->doesntExist())) {
            $hu->order->update(['status' => 'packed']);
        }

        AuditLogger::log('Handling unit sealed', $hu->hu_number, $hu);

        return $hu;
    }

    public function createShipment(array $data): Shipment
    {
        return Shipment::create($data + [
            'shipment_number' => 'SHP-'.now()->format('Y').'-'.str_pad((string) (Shipment::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'planned',
        ]);
    }

    public function loadHandlingUnit(Shipment $shipment, HandlingUnit $hu, ?User $actor = null): HandlingUnit
    {
        $this->assertHuStatus($hu, ['sealed']);
        if ($hu->order->site_id) {
            abort_unless($actor, 403);
            SiteLocation::assertCanAccessSiteRecord($actor, (int) $hu->order->site_id);
        }
        if ($hu->order->site_id && ($hu->order->status !== 'packed' && $hu->order->status !== 'dispatch_planned')) {
            throw ValidationException::withMessages(['status' => 'Full order must be packed and sealed before loading.']);
        }
        if ($hu->shipment_id !== null) {
            throw ValidationException::withMessages(['status' => 'Handling unit is already assigned to a shipment.']);
        }
        $hu->update(['shipment_id' => $shipment->id]);
        $hu->order()->where('status', 'packed')->update(['status' => 'dispatch_planned']);
        $shipment->update(['status' => 'loading']);

        return $hu;
    }

    public function releaseShipment(Shipment $shipment, User $actor): Shipment
    {
        DB::transaction(function () use ($shipment, $actor): void {
            $shipment = Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if ($shipment->status !== 'loading' || $shipment->handlingUnits()->count() === 0) {
                throw ValidationException::withMessages(['status' => 'Only a loaded shipment can be released once.']);
            }
            $orderIds = $shipment->handlingUnits()->pluck('customer_order_id')->unique();
            foreach (CustomerOrder::whereIn('id', $orderIds)->get() as $order) {
                if (! $order->site_id) continue;
                SiteLocation::assertCanAccessSiteRecord($actor, (int) $order->site_id);
                if ($order->status !== 'dispatch_planned' || $order->lines()->whereRaw('qty_packed < qty_ordered OR qty_picked < qty_ordered')->exists()
                    || $order->handlingUnits()->where('shipment_id', '!=', $shipment->id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'Shipment must contain every sealed handling unit of each fully packed sales order.']);
                }
            }
            $allocations = Allocation::whereHas('orderLine', fn ($q) => $q->whereIn('customer_order_id', $orderIds))
                ->with(['inventoryLot', 'pickTask'])->orderBy('id')->get();
            foreach ($allocations as $allocation) {
                $lot = $allocation->inventoryLot;
                $actorSite = SiteLocation::forUser($actor);
                if ($actorSite !== null && ($lot->location_id === null || (int) $lot->location_id !== $actorSite)) {
                    throw ValidationException::withMessages(['status' => 'This shipment contains stock from another site.']);
                }
                $qty = (float) ($allocation->pickTask?->qty_picked ?? $allocation->qty);
                if ($qty <= 0) {
                    continue;
                }
                if ($lot->inventory_item_id) {
                    StockItem::whereKey($lot->inventory_item_id)->lockForUpdate()->firstOrFail();
                    $item = StockItem::findOrFail($lot->inventory_item_id);
                    $stock = app(InventoryService::class)->atWarehouse((int) $lot->warehouse_id)->balances(collect([$item]))[$item->id]['final'];
                    if ($qty > $stock + 0.0005) {
                        throw ValidationException::withMessages(['status' => 'Insufficient FG stock in the mapped warehouse for dispatch.']);
                    }
                    StockMovement::create([
                        'item_id' => $item->id, 'type' => 'dispatched', 'qty' => $qty,
                        'location_id' => $lot->location_id, 'warehouse_id' => $lot->warehouse_id,
                        'source_key' => 'flow-dispatch:'.$shipment->id.':'.$allocation->id,
                        'note' => $shipment->shipment_number, 'created_by' => $actor->id,
                    ]);
                }
                $this->postMovement($lot, 'dispatch', $qty, $actor, Shipment::class, $shipment->id);
                $lot->decrement('qty_picked', min($qty, (float) $lot->qty_picked));
                if ((float) $lot->fresh()->qty_available <= 0 && (float) $lot->fresh()->qty_allocated <= 0 && (float) $lot->fresh()->qty_picked <= 0) {
                    $lot->update(['status' => 'depleted']);
                }
            }
            $shipment->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);
            CustomerOrder::whereIn('id', $orderIds)->where('status', 'dispatch_planned')->update(['status' => 'in_transit']);
            foreach (CustomerOrder::whereIn('id', $orderIds)->whereNotNull('site_id')->get() as $order) {
                app(ProductionTaskService::class)->done('order:'.$order->id.':logistics', $actor->id);
                app(ProductionTaskService::class)->open('shipment:'.$shipment->id.':order:'.$order->id.':finance',
                    'Finance review for '.$order->order_number.' / '.$shipment->shipment_number,
                    'finance.review.view', (int) $order->site_id, $actor->id, $shipment);
            }
        });

        AuditLogger::log('Shipment released', $shipment->shipment_number, $shipment);

        return $shipment;
    }

    public function recordTemperature(Shipment $shipment, array $data, User $actor): TemperatureEvent
    {
        $event = TemperatureEvent::create($data + ['shipment_id' => $shipment->id, 'recorded_by' => $actor->id]);

        if ($event->excursion) {
            AuditLogger::log('Temperature excursion detected', $shipment->shipment_number, $event);
        }

        return $event;
    }

    public function dispositionExcursion(TemperatureEvent $event, string $disposition): TemperatureEvent
    {
        $event->update(['disposition' => $disposition]);
        AuditLogger::log('Temperature excursion dispositioned', $disposition, $event);

        return $event;
    }

    public function recordDelivery(Shipment $shipment, CustomerOrder $order, array $data, User $actor): Delivery
    {
        if ($shipment->hasOpenExcursion()) {
            throw ValidationException::withMessages(['status' => 'Shipment has an undispositioned temperature excursion.']);
        }

        $delivery = Delivery::create($data + ['shipment_id' => $shipment->id, 'customer_order_id' => $order->id]);
        $order->update(['status' => 'pod_received']);

        AuditLogger::log('POD received', $order->order_number, $delivery);

        return $delivery;
    }

    public function closeDelivery(Delivery $delivery, User $actor): Delivery
    {
        if (! $delivery->pod_reference) {
            throw ValidationException::withMessages(['status' => 'POD reference is required before delivery can be closed.']);
        }

        $delivery->update(['closed_by' => $actor->id, 'closed_at' => now()]);
        $delivery->order->update(['status' => 'closed']);

        $allDelivered = $delivery->shipment->deliveries()->whereNull('closed_at')->doesntExist();
        if ($allDelivered) {
            $delivery->shipment->update(['status' => 'closed']);
        }

        AuditLogger::log('Delivery closed', $delivery->order->order_number, $delivery);

        return $delivery;
    }

    public function requestReturn(array $data, User $actor): \App\Models\Flow\ReturnRequest
    {
        return \App\Models\Flow\ReturnRequest::create($data + [
            'rma_number' => 'RMA-'.now()->format('Y').'-'.str_pad((string) (\App\Models\Flow\ReturnRequest::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'requested',
            'requested_by' => $actor->id,
        ]);
    }

    public function inspectReturn(\App\Models\Flow\ReturnRequest $return, array $data, User $actor): \App\Models\Flow\ReturnRequest
    {
        $return->update($data + ['status' => 'dispositioned', 'inspected_by' => $actor->id]);
        AuditLogger::log('Return inspected and dispositioned', $return->rma_number.' -> '.$return->disposition, $return);

        return $return;
    }

    private function assertStatus(CustomerOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Order {$order->order_number} is '{$order->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }

    private function assertHuStatus(HandlingUnit $hu, array $allowed): void
    {
        if (! in_array($hu->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Handling unit {$hu->hu_number} is '{$hu->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }

    private function postMovement(InventoryLot $lot, string $type, float $qty, ?User $actor, ?string $referenceType = null, ?int $referenceId = null, array $extra = []): void
    {
        InventoryMovement::create([
            'inventory_lot_id' => $lot->id,
            'movement_type' => $type,
            'qty' => $qty,
            'warehouse' => $lot->warehouse,
            'zone' => $extra['zone'] ?? $lot->zone,
            'bin' => $extra['bin'] ?? $lot->bin,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actor?->id,
        ]);
    }
}
