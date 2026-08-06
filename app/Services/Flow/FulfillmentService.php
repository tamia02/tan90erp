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
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Owns Flow's order-to-delivery state machine (ARCHITECTURE.md #5) and the
// append-only inventory ledger (ARCHITECTURE.md #4) - flow_inventory_lots
// balances are only ever changed alongside a flow_inventory_movements row,
// never edited bare. FEFO allocation only considers InventoryLot::isAtpEligible().
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
            $lot = InventoryLot::create([
                'finished_good_id' => $batch->workOrder->finished_good_id,
                'forge_batch_id' => $batch->id,
                'lot_number' => $batch->batch_number,
                'warehouse' => $data['warehouse'] ?? 'Bhiwandi FG Warehouse',
                'zone' => $data['zone'] ?? null,
                'bin' => $data['bin'] ?? null,
                'qty_received' => $batch->qty,
                'qty_available' => $batch->qty,
                'uom' => $batch->uom,
                'quality_status' => 'released',
                'expiry_date' => $batch->shelf_life_date,
                'status' => $data['bin'] ?? null ? 'available' : 'staged',
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);

            $this->postMovement($lot, 'fg_receipt', $lot->qty_received, $actor, Batch::class, $batch->id, $data);

            return $lot;
        });

        AuditLogger::log('FG received into Flow', $lot->lot_number, $lot);

        return $lot;
    }

    public function putaway(InventoryLot $lot, string $zone, string $bin, User $actor): InventoryLot
    {
        if ($lot->status !== 'staged') {
            throw ValidationException::withMessages(['status' => 'Only a staged lot can be put away.']);
        }

        $lot->update(['zone' => $zone, 'bin' => $bin, 'status' => 'available']);
        $this->postMovement($lot, 'putaway', $lot->qty_available, $actor, null, null, ['zone' => $zone, 'bin' => $bin]);

        AuditLogger::log('FG lot put away', $lot->lot_number.' -> '.$bin, $lot);

        return $lot;
    }

    public function createOrder(array $data, User $actor): CustomerOrder
    {
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

        return $this->runAtpAndAllocate($order);
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

                $lots = InventoryLot::where('finished_good_id', $line->finished_good_id)
                    ->where('status', 'available')
                    ->where('quality_status', 'released')
                    ->where('qty_available', '>', 0)
                    ->when($order->min_shelf_life_days > 0, fn ($q) => $q->where('expiry_date', '>=', now()->addDays($order->min_shelf_life_days)))
                    ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                    ->lockForUpdate()
                    ->get()
                    ->filter->isAtpEligible();

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
        if (in_array($order->status, ['atp_confirmed', 'atp_partial'], true) && $order->allocations()->exists()) {
            $order->update(['status' => 'allocated']);
        }

        AuditLogger::log('ATP run and FEFO allocation complete', $order->order_number.' -> '.$order->status, $order);

        return $order;
    }

    public function buildWave(array $orderLineIds, string $warehouse, User $actor): PickingWave
    {
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
            $this->postMovement($lot, 'pick', $qtyPicked, $actor, PickTask::class, $task->id);

            $line = $allocation->orderLine;
            $line->increment('qty_picked', $qtyPicked);
            $allocation->update(['status' => 'picked']);

            $order = $line->order;
            if ($order->status === 'waved') {
                $order->update(['status' => 'picking']);
            }

            $orderTaskStatuses = PickTask::whereHas('allocation.orderLine', fn ($q) => $q->where('customer_order_id', $order->id))->pluck('status');
            if ($orderTaskStatuses->every(fn ($s) => in_array($s, ['picked', 'short'], true))) {
                $order->update(['status' => 'picked']);
            }
        });

        AuditLogger::log('Pick confirmed', 'task #'.$task->id.' qty '.$qtyPicked, $task);

        return $task;
    }

    public function packIntoHandlingUnit(CustomerOrder $order, array $data, User $actor): HandlingUnit
    {
        $hu = HandlingUnit::create([
            'customer_order_id' => $order->id,
            'hu_number' => 'HU-'.now()->format('Y').'-'.str_pad((string) (HandlingUnit::count() + 1), 5, '0', STR_PAD_LEFT),
            'weight_kg' => $data['weight_kg'] ?? null,
            'status' => 'packed',
        ]);

        $order->lines()->increment('qty_packed', $data['qty_packed'] ?? 0);
        $order->update(['status' => 'packing']);

        AuditLogger::log('Handling unit packed', $hu->hu_number, $hu);

        return $hu;
    }

    public function sealHandlingUnit(HandlingUnit $hu): HandlingUnit
    {
        $this->assertHuStatus($hu, ['packed']);
        $hu->update(['status' => 'sealed', 'sealed_at' => now()]);

        if ($hu->order->handlingUnits()->where('status', '!=', 'sealed')->doesntExist()) {
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

    public function loadHandlingUnit(Shipment $shipment, HandlingUnit $hu): HandlingUnit
    {
        $this->assertHuStatus($hu, ['sealed']);
        $hu->update(['shipment_id' => $shipment->id]);
        $hu->order()->where('status', 'packed')->update(['status' => 'dispatch_planned']);
        $shipment->update(['status' => 'loading']);

        return $hu;
    }

    public function releaseShipment(Shipment $shipment, User $actor): Shipment
    {
        if ($shipment->handlingUnits()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'Shipment has no handling units loaded.']);
        }

        $shipment->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);
        CustomerOrder::whereIn('id', $shipment->handlingUnits()->pluck('customer_order_id'))
            ->where('status', 'dispatch_planned')->update(['status' => 'in_transit']);

        foreach ($shipment->handlingUnits as $hu) {
            foreach ($hu->order->lines as $line) {
                foreach ($line->allocations as $allocation) {
                    $this->postMovement($allocation->inventoryLot, 'dispatch', $allocation->qty, $actor, Shipment::class, $shipment->id);
                }
            }
        }

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
