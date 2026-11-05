<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\Delivery;
use App\Models\Flow\HandlingUnit;
use App\Models\Flow\Shipment;
use App\Models\Flow\TemperatureEvent;
use App\Models\User;
use App\Services\Flow\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlowShipmentIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => Role::Admin, 'access_mode' => 'advanced', 'super_admin' => true]);
    }

    private function order(): CustomerOrder
    {
        return CustomerOrder::create(['order_number' => 'AUDIT-'.Str::uuid(), 'customer_name' => 'Isolated audit fixture', 'status' => 'packed']);
    }

    private function shipment(): Shipment
    {
        return Shipment::create(['shipment_number' => 'AUDIT-'.Str::uuid(), 'status' => 'released']);
    }

    private function unit(CustomerOrder $order, ?Shipment $shipment = null): HandlingUnit
    {
        return HandlingUnit::create(['customer_order_id' => $order->id, 'shipment_id' => $shipment?->id, 'hu_number' => 'AUDIT-'.Str::uuid(), 'status' => 'sealed']);
    }

    public function test_released_shipment_rejects_additional_loading(): void
    {
        $shipment = $this->shipment();
        $unit = $this->unit($this->order());
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->loadHandlingUnit($shipment, $unit, $this->actor);
    }

    public function test_pod_rejects_order_not_carried_by_shipment(): void
    {
        $shipment = $this->shipment();
        $this->unit($this->order(), $shipment);
        $unrelated = $this->order();
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->recordDelivery($shipment, $unrelated, ['pod_reference' => 'AUDIT-POD', 'delivered_at' => now()], $this->actor);
    }

    public function test_shipment_stays_open_until_every_loaded_order_is_delivered(): void
    {
        $shipment = $this->shipment();
        $first = $this->order();
        $second = $this->order();
        $this->unit($first, $shipment);
        $this->unit($second, $shipment);
        $delivery = Delivery::create(['shipment_id' => $shipment->id, 'customer_order_id' => $first->id, 'pod_reference' => 'AUDIT-POD', 'delivered_at' => now()]);
        app(FulfillmentService::class)->closeDelivery($delivery, $this->actor);
        $this->assertNotSame('closed', $shipment->fresh()->status, 'A second loaded order has no delivery record.');
    }

    public function test_unresolved_temperature_excursion_blocks_delivery(): void
    {
        $shipment = $this->shipment();
        TemperatureEvent::create(['shipment_id' => $shipment->id, 'reading_celsius' => 25, 'excursion' => true]);
        $order = $this->order();
        $this->unit($order, $shipment);
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->recordDelivery($shipment, $order, ['pod_reference' => 'AUDIT-POD'], $this->actor);
    }

    public function test_delivery_is_rejected_before_shipment_dispatch(): void
    {
        $shipment = $this->shipment();
        $shipment->update(['status' => 'planned']);
        $order = $this->order();
        $this->unit($order, $shipment);
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->recordDelivery($shipment, $order, ['pod_reference' => 'AUDIT-POD', 'delivered_at' => now()], $this->actor);
    }

    public function test_missing_pod_reference_blocks_closure(): void
    {
        $shipment = $this->shipment();
        $order = $this->order();
        $delivery = Delivery::create(['shipment_id' => $shipment->id, 'customer_order_id' => $order->id]);
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->closeDelivery($delivery, $this->actor);
    }

    public function test_valid_single_order_delivery_closes(): void
    {
        $shipment = $this->shipment();
        $order = $this->order();
        $this->unit($order, $shipment);
        $service = app(FulfillmentService::class);
        $delivery = $service->recordDelivery($shipment, $order, ['pod_reference' => 'AUDIT-POD', 'delivered_at' => now()], $this->actor);
        $service->closeDelivery($delivery, $this->actor);
        $this->assertSame('closed', $shipment->fresh()->status);
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_legacy_shipment_number_sequence_continues_without_reusing_a_deleted_number(): void
    {
        $prefix = 'SHP-'.now()->format('Y').'-';
        Shipment::create(['shipment_number' => $prefix.'0042', 'status' => 'planned']);
        $service = app(FulfillmentService::class);
        $first = $service->createShipment([]);
        $this->assertSame($prefix.'0043', $first->shipment_number);
        $first->delete();
        $this->assertSame($prefix.'0044', $service->createShipment([])->shipment_number);
    }
}
