<?php

namespace Tests\Feature;

use App\Models\Forge\Batch;
use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use Tests\TestCase;

class FlowGoldenPathTest extends TestCase
{
    private function releasedBatch(): Batch
    {
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        $wo = WorkOrder::create([
            'wo_number' => 'WO-FLOWTEST-'.uniqid(),
            'finished_good_id' => $finishedGood->id,
            'target_qty' => 200, 'uom' => 'EA', 'status' => 'released_to_fg',
        ]);

        return Batch::create([
            'work_order_id' => $wo->id,
            'batch_number' => 'BATCH-FLOWTEST-'.uniqid(),
            'qty' => 200, 'uom' => 'EA', 'status' => 'released', 'released_at' => now(),
        ]);
    }

    public function test_all_flow_index_pages_load_for_fulfilment_head(): void
    {
        $head = User::where('email', 'head.fulfilment@tan90.demo')->firstOrFail();

        foreach ([
            'flow.dashboard', 'flow.inventory.index', 'flow.orders.index', 'flow.waves.index',
            'flow.packing.index', 'flow.dispatch.index', 'flow.deliveries.index', 'flow.returns.index',
        ] as $route) {
            $this->actingAs($head)->get(route($route))->assertOk();
        }
    }

    public function test_full_order_lifecycle_fg_receipt_to_delivery_closure(): void
    {
        $fgStore = User::where('email', 'fgstore@tan90.demo')->firstOrFail();
        $pickPack = User::where('email', 'pickpack@tan90.demo')->firstOrFail();
        $dispatch = User::where('email', 'dispatch@tan90.demo')->firstOrFail();
        $transport = User::where('email', 'transport@tan90.demo')->firstOrFail();
        $closure = User::where('email', 'closure@tan90.demo')->firstOrFail();
        $customerManager = User::where('email', 'manager.customer@tan90.demo')->firstOrFail();

        $batch = $this->releasedBatch();
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        // Receive FG from Forge
        $this->actingAs($fgStore)->post(route('flow.inventory.receive', $batch), [
            'zone' => 'Z1', 'bin' => 'B-01',
        ])->assertRedirect();

        $lot = \App\Models\Flow\InventoryLot::where('forge_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('available', $lot->status);
        $this->assertEquals(200, $lot->qty_available);

        // Create order
        $this->actingAs($customerManager)->post(route('flow.orders.store'), [
            'customer_name' => 'Test Retail Chain',
            'destination' => 'Pune',
            'temperature_requirement' => 'ambient',
            'min_shelf_life_days' => 0,
            'requested_date' => now()->addDays(2)->toDateString(),
        ])->assertRedirect();

        $order = \App\Models\Flow\CustomerOrder::latest()->firstOrFail();
        $this->actingAs($customerManager)->post(route('flow.orders.lines.store', $order), [
            'finished_good_id' => $finishedGood->id, 'qty_ordered' => 150, 'uom' => 'EA',
        ])->assertRedirect();

        $this->actingAs($customerManager)->post(route('flow.orders.validate', $order))->assertRedirect();
        $this->assertSame('validated', $order->fresh()->status);

        $this->actingAs($customerManager)->post(route('flow.orders.release', $order))->assertRedirect();
        $this->assertSame('allocated', $order->fresh()->status);

        $line = $order->lines()->firstOrFail();
        $this->assertEquals(150, $line->fresh()->qty_allocated);
        $this->assertEquals(50, $lot->fresh()->qty_available);
        $this->assertEquals(150, $lot->fresh()->qty_allocated);

        // Wave and pick
        $this->actingAs($pickPack)->post(route('flow.waves.store'), [
            'order_line_ids' => [$line->id], 'warehouse' => 'Bhiwandi FG Warehouse',
        ])->assertRedirect();

        $wave = \App\Models\Flow\PickingWave::latest()->firstOrFail();
        $this->actingAs($pickPack)->post(route('flow.waves.publish', $wave))->assertRedirect();
        $this->assertSame('waved', $order->fresh()->status);

        $task = $wave->pickTasks()->firstOrFail();
        $this->actingAs($pickPack)->post(route('flow.waves.pick-tasks.confirm', $task), ['qty_picked' => 150])->assertRedirect();
        $this->assertSame('picked', $order->fresh()->status);
        $this->assertEquals(150, $lot->fresh()->qty_picked);

        // Pack and seal
        $this->actingAs($pickPack)->post(route('flow.packing.store', $order), [
            'qty_packed' => 150, 'weight_kg' => 45,
        ])->assertRedirect();

        $hu = $order->fresh()->handlingUnits()->firstOrFail();
        $this->actingAs($pickPack)->post(route('flow.packing.seal', $hu))->assertRedirect();
        $this->assertSame('sealed', $hu->fresh()->status);
        $this->assertSame('packed', $order->fresh()->status);

        // Dispatch
        $this->actingAs($dispatch)->post(route('flow.dispatch.store'), [
            'warehouse' => 'Bhiwandi FG Warehouse', 'dock_number' => 'D1',
            'transporter' => 'Test Transport Co', 'vehicle_number' => 'MH-04-TEST', 'driver_name' => 'Test Driver',
        ])->assertRedirect();

        $shipment = \App\Models\Flow\Shipment::latest()->firstOrFail();
        $this->actingAs($dispatch)->post(route('flow.dispatch.load', [$shipment, $hu]))->assertRedirect();
        $this->assertSame($shipment->id, $hu->fresh()->shipment_id);

        $this->actingAs($dispatch)->post(route('flow.dispatch.release', $shipment))->assertRedirect();
        $this->assertSame('released', $shipment->fresh()->status);
        $this->assertSame('in_transit', $order->fresh()->status);

        // Temperature reading, no excursion
        $this->actingAs($transport)->post(route('flow.dispatch.temperature', $shipment), [
            'reading_celsius' => 4.5, 'excursion' => false,
        ])->assertRedirect();

        // POD and closure
        $this->actingAs($closure)->post(route('flow.deliveries.store', $shipment), [
            'customer_order_id' => $order->id, 'receiver_name' => 'Warehouse Manager',
            'qty_accepted' => 150, 'pod_reference' => 'POD-TEST-001',
        ])->assertRedirect();
        $this->assertSame('pod_received', $order->fresh()->status);

        $delivery = \App\Models\Flow\Delivery::where('customer_order_id', $order->id)->firstOrFail();
        $this->actingAs($closure)->post(route('flow.deliveries.close', $delivery))->assertRedirect();
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertNotNull($delivery->fresh()->closed_at);

        // Return / RMA
        $this->actingAs($closure)->post(route('flow.returns.store'), [
            'customer_order_id' => $order->id, 'reason' => 'Damaged in transit', 'qty' => 5, 'uom' => 'EA',
        ])->assertRedirect();

        $return = \App\Models\Flow\ReturnRequest::where('customer_order_id', $order->id)->firstOrFail();
        $this->actingAs($fgStore)->post(route('flow.returns.inspect', $return), [
            'disposition' => 'scrap', 'inspection_notes' => 'Crushed cartons', 'claim_raised' => true, 'claim_amount' => 500,
        ])->assertRedirect();
        $this->assertSame('dispositioned', $return->fresh()->status);
        $this->assertSame('scrap', $return->fresh()->disposition);
        $this->assertSame('pending', $return->fresh()->claim_status);
    }

    public function test_short_pick_leaves_pick_task_status_short(): void
    {
        $fgStore = User::where('email', 'fgstore@tan90.demo')->firstOrFail();
        $pickPack = User::where('email', 'pickpack@tan90.demo')->firstOrFail();
        $customerManager = User::where('email', 'manager.customer@tan90.demo')->firstOrFail();

        $batch = $this->releasedBatch();
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        $this->actingAs($fgStore)->post(route('flow.inventory.receive', $batch), ['zone' => 'Z1', 'bin' => 'B-02'])->assertRedirect();

        $this->actingAs($customerManager)->post(route('flow.orders.store'), ['customer_name' => 'Short Pick Test Co'])->assertRedirect();
        $order = \App\Models\Flow\CustomerOrder::latest()->firstOrFail();
        $this->actingAs($customerManager)->post(route('flow.orders.lines.store', $order), [
            'finished_good_id' => $finishedGood->id, 'qty_ordered' => 100, 'uom' => 'EA',
        ])->assertRedirect();
        $this->actingAs($customerManager)->post(route('flow.orders.validate', $order))->assertRedirect();
        $this->actingAs($customerManager)->post(route('flow.orders.release', $order))->assertRedirect();

        $line = $order->lines()->firstOrFail();
        $this->actingAs($pickPack)->post(route('flow.waves.store'), ['order_line_ids' => [$line->id]])->assertRedirect();
        $wave = \App\Models\Flow\PickingWave::latest()->firstOrFail();
        $this->actingAs($pickPack)->post(route('flow.waves.publish', $wave))->assertRedirect();

        // FEFO may split the 100-unit demand across more than one lot if an
        // earlier test left spare qty on FG-PCM500-BLUE, so confirm every
        // task in the wave short by 1 unit rather than assuming a single task.
        foreach ($wave->pickTasks as $task) {
            $shortQty = (float) $task->qty_to_pick - 1;
            $this->actingAs($pickPack)->post(route('flow.waves.pick-tasks.confirm', $task), ['qty_picked' => $shortQty])->assertRedirect();
            $this->assertSame('short', $task->fresh()->status);
        }

        $this->assertSame('picked', $order->fresh()->status);
    }
}
