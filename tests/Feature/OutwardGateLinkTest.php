<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Outward\OutwardShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// The Guard's 4-step outward gate flow joined to the Outward Shipment:
// gate pass made from a ready shipment -> approval -> loading -> Guard exit,
// which marks the shipment dispatched and only then deducts stock.
class OutwardGateLinkTest extends TestCase
{
    use RefreshDatabase;

    private const CHENNAI = '33AAGCT9099K1ZP';

    private function shipment(InventoryItem $item, float $qty): OutwardShipment
    {
        // Same GSTIN transfer under Rs 50,000 = Delivery Challan, ready at once.
        return app(OutwardShipmentService::class)->create([
            'purpose' => 'transfer', 'from_name' => 'CHENNAI', 'from_gstin' => self::CHENNAI, 'from_state' => 'Tamil Nadu',
            'to_name' => 'Chennai Plant 2', 'to_gstin' => self::CHENNAI, 'to_state' => 'Tamil Nadu',
        ], [['item_id' => $item->id, 'qty' => $qty, 'rate' => 100]], User::factory()->create(['role' => Role::StoreManager]));
    }

    private function stock(InventoryItem $item): float
    {
        return app(InventoryService::class)->available($item->fresh());
    }

    public function test_guard_exit_dispatches_the_linked_shipment_and_deducts_stock(): void
    {
        $guard = User::factory()->create(['role' => Role::Guard]);
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $executive = User::factory()->create(['role' => Role::StoreExec]);
        $fg = InventoryItem::create(['sku' => 'Gate Link Panel', 'item_type' => 'fg', 'unit' => 'PCS', 'opening_qty' => 100]);
        $shipment = $this->shipment($fg, 10);
        $this->assertSame('ready', $shipment->status);

        // 1. Guard: pick the shipment - everything fills in.
        $this->actingAs($guard);
        Volt::test('guard.bill-scan')
            ->call('setEntryType', 'outward')
            ->assertSee($shipment->number)
            ->call('selectOutwardShipment', $shipment->id)
            ->assertSet('vendorName', 'Chennai Plant 2')
            ->assertSet('invoiceNumber', $shipment->challan_number)
            ->assertSet('invoiceQty', '10')
            ->assertSet('location', 'chennai')
            ->set('driverName', 'Link Driver')->set('vehicleNumber', 'TN 01 AB 1234')
            ->call('saveEntry')->assertHasNoErrors();
        $gate = GateEntry::where('outward_shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame($gate->id, $shipment->fresh()->gate_entry_id);
        $this->assertSame(['outward', 'pending_validation'], [$gate->entry_type, $gate->status]);

        // The shipment shows the truck, can't be cancelled, and is not offered
        // for a second gate pass. There is no manual dispatch any more.
        $this->actingAs($manager);
        Volt::test('inventory.dispatch')->assertSee($gate->gate_no)->assertSee('TN 01 AB 1234')->assertDontSee('Mark dispatched')
            ->call('cancelShipment', $shipment->id)->assertHasErrors('cancel');
        $this->assertSame('TN 01 AB 1234', $shipment->fresh()->vehicle_number); // truck number copied for the E-Way Bill
        $this->assertCount(0, app(OutwardShipmentService::class)->openForGate());

        // 2-3. Approval and loading: unchanged.
        Volt::test('store-manager.entry-approvals')->call('openOutwardApproval', $gate->id)->set('outwardDock', 'Dispatch Bay 1')
            ->call('approveOutward', $gate->id)->assertHasNoErrors();
        $this->actingAs($executive);
        Volt::test('unloading.outward')->call('startLoading', $gate->id)->assertHasNoErrors();
        Volt::test('unloading.outward')->call('openCompletion', $gate->id)
            ->set('documentsChecked.invoice', true)->set('documentsChecked.bill', true)->set('documentsChecked.pod', true)->set('documentsChecked.lr', true)
            ->set('documentsPackConfirmed', true)->call('completeLoading', $gate->id)->assertHasNoErrors();
        $this->assertSame('loaded', $gate->fresh()->status);
        $this->assertSame(100.0, $this->stock($fg)); // nothing deducted while the truck is still inside

        // 4. Guard confirms exit -> shipment dispatched, stock goes down.
        $this->actingAs($guard);
        Volt::test('guard.entry-detail', ['entry' => $gate->fresh()])->call('confirmExit')->assertHasNoErrors();
        $this->assertSame('dispatched', $gate->fresh()->status);
        $this->assertSame('dispatched', $shipment->fresh()->status);
        $this->assertSame(90.0, $this->stock($fg));
    }

    public function test_short_stock_blocks_the_exit_and_nothing_changes(): void
    {
        $fg = InventoryItem::create(['sku' => 'Short Panel', 'item_type' => 'fg', 'unit' => 'PCS', 'opening_qty' => 10]);
        $shipment = $this->shipment($fg, 8);
        $fg->update(['opening_qty' => 5]); // stock used elsewhere after the shipment was made
        $gate = GateEntry::factory()->create(['entry_type' => 'outward', 'status' => 'loaded', 'outward_shipment_id' => $shipment->id]);
        $shipment->update(['gate_entry_id' => $gate->id]);

        $this->actingAs(User::factory()->create(['role' => Role::Guard]));
        Volt::test('guard.entry-detail', ['entry' => $gate->fresh()])->call('confirmExit')
            ->assertHasErrors('exit')->assertSee('in stock');
        $this->assertSame(['loaded', 'ready', 5.0], [$gate->fresh()->status, $shipment->fresh()->status, $this->stock($fg)]);
    }

    public function test_truck_can_arrive_before_documents_and_manager_links_it_at_approval(): void
    {
        $fg = InventoryItem::create(['sku' => 'Early Truck Panel', 'item_type' => 'fg', 'unit' => 'PCS', 'opening_qty' => 50]);
        // B2B sale: Tax Invoice + IRN + E-Way Bill, documents not done yet.
        $shipment = app(OutwardShipmentService::class)->create([
            'purpose' => 'sales', 'from_name' => 'CHENNAI', 'from_gstin' => self::CHENNAI, 'from_state' => 'Tamil Nadu',
            'to_name' => 'B2B Buyer', 'to_gstin' => '29ABCDE1234F1Z5', 'to_state' => 'Karnataka',
        ], [['item_id' => $fg->id, 'qty' => 5, 'rate' => 100]], User::factory()->create(['role' => Role::StoreManager]));
        $this->assertSame('documents_pending', $shipment->status);

        // Guard logs the truck without picking a shipment.
        $guard = User::factory()->create(['role' => Role::Guard]);
        $this->actingAs($guard);
        Volt::test('guard.bill-scan')->call('setEntryType', 'outward')->assertSee($shipment->number)
            ->set('driverName', 'D')->set('vehicleNumber', 'KA 01 X 9')->set('poNumber', 'PKG-1')->set('vendorName', 'B2B Buyer')
            ->set('invoiceNumber', 'TBD')->set('material', 'Panels')->set('invoiceQty', '5')
            ->call('saveEntry')->assertHasNoErrors();
        $gate = GateEntry::where('vehicle_number', 'KA 01 X 9')->firstOrFail();

        // Store Manager picks the shipment while assigning the dock.
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        Volt::test('store-manager.entry-approvals')->call('openOutwardApproval', $gate->id)->assertSee($shipment->number)
            ->set('linkShipmentId', (string) $shipment->id)->set('outwardDock', 'Dispatch Bay 2')
            ->call('approveOutward', $gate->id)->assertHasNoErrors();
        $this->assertSame([$shipment->id, 'KA 01 X 9'], [$gate->fresh()->outward_shipment_id, $shipment->fresh()->vehicle_number]);

        // Loaded, but documents still missing -> the Guard can't let it go.
        $gate->update(['status' => 'loaded', 'loaded_at' => now()]);
        $this->actingAs($guard);
        Volt::test('guard.entry-detail', ['entry' => $gate->fresh()])->call('confirmExit')->assertHasErrors('exit')->assertSee('E-Way Bill');
        $this->assertSame(['loaded', 50.0], [$gate->fresh()->status, $this->stock($fg)]);

        // Documents uploaded -> exit dispatches and deducts stock.
        $shipment->update(['invoice_number' => 'INV-9', 'invoice_date' => today(), 'invoice_file' => 'x.pdf', 'irn' => 'IRN9', 'irn_ack_number' => '9',
            'ewb_number' => '331234567890', 'ewb_valid_until' => today()->addDay(), 'ewb_file' => 'e.pdf', 'status' => 'ready']);
        Volt::test('guard.entry-detail', ['entry' => $gate->fresh()])->call('confirmExit')->assertHasNoErrors();
        $this->assertSame(['dispatched', 'dispatched', 45.0], [$gate->fresh()->status, $shipment->fresh()->status, $this->stock($fg)]);
    }

    public function test_old_outward_pass_without_a_shipment_works_as_before(): void
    {
        $gate = GateEntry::factory()->create(['entry_type' => 'outward', 'status' => 'loaded']);
        $this->actingAs(User::factory()->create(['role' => Role::Guard]));
        Volt::test('guard.entry-detail', ['entry' => $gate])->call('confirmExit')->assertHasNoErrors();
        $this->assertSame('dispatched', $gate->fresh()->status);
    }
}
