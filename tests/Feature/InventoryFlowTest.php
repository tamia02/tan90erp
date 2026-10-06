<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryItem $compound;

    private InventoryItem $pouch;

    private InventoryItem $fg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->compound = InventoryItem::create(['sku' => 'PCM Compound', 'item_type' => 'rm', 'unit' => 'KG', 'opening_qty' => 100]);
        $this->pouch = InventoryItem::create(['sku' => 'Pouch 500g', 'item_type' => 'pm', 'unit' => 'NOS', 'opening_qty' => 0]);
        $this->fg = InventoryItem::create(['sku' => 'PCM Pouch 500g Blue', 'item_type' => 'fg', 'unit' => 'NOS', 'opening_qty' => 0]);
        $this->fg->bomLines()->create(['component_item_id' => $this->compound->id, 'qty_per_unit' => 0.5]);
        $this->fg->bomLines()->create(['component_item_id' => $this->pouch->id, 'qty_per_unit' => 1]);

        $this->grnReceive('Pouch 500g', 50);
    }

    private function grnReceive(string $sku, int $qty, string $bucket = 'available'): void
    {
        $gate = GateEntry::factory()->create();
        LedgerEntry::create(['gate_entry_id' => $gate->id, 'sku' => $sku, 'bin' => 'BIN-1', 'bucket' => $bucket, 'qty' => $qty]);
    }

    private function balance(InventoryItem $item): array
    {
        return app(InventoryService::class)->balances(collect([$item->fresh()]))[$item->id];
    }

    public function test_grn_receipts_count_as_received_and_only_accepted_stock(): void
    {
        $this->grnReceive('Pouch 500g', 7, 'defective');

        $pouch = $this->balance($this->pouch);
        $this->assertSame(50.0, $pouch['received']);
        $this->assertSame(50.0, $pouch['final']);
        $this->assertSame(100.0, $this->balance($this->compound)['final']);
    }

    public function test_create_bundle_converts_rm_pm_into_fg_with_wastage(): void
    {
        $production = app(InventoryService::class)->produce($this->fg, 40, [$this->compound->id => 2], 'Shift A', $this->admin);

        $this->assertSame('PRD-00001', $production->number);
        $compound = $this->balance($this->compound);
        $this->assertSame(20.0, $compound['consumed']);
        $this->assertSame(2.0, $compound['wastage']);
        $this->assertSame(78.0, $compound['final']); // 100 - 20 - 2
        $this->assertSame(10.0, $this->balance($this->pouch)['final']); // 50 - 40
        $fg = $this->balance($this->fg);
        $this->assertSame(40.0, $fg['produced']);
        $this->assertSame(40.0, $fg['final']);
    }

    public function test_create_bundle_is_blocked_when_material_is_short_and_nothing_is_written(): void
    {
        try {
            app(InventoryService::class)->produce($this->fg, 60, [], null, $this->admin); // needs 60 pouches, only 50
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Not enough Pouch 500g', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0.0, $this->balance($this->fg)['final']);
    }

    public function test_dispatch_reduces_fg_and_cannot_exceed_stock(): void
    {
        $service = app(InventoryService::class);
        $service->produce($this->fg, 40, [], null, $this->admin);

        $dispatch = $service->dispatch($this->fg, 15, 'Cold Chain Foods', 'INV-1', null, $this->admin);
        $this->assertSame('DSP-00001', $dispatch->number);
        $fg = $this->balance($this->fg);
        $this->assertSame(15.0, $fg['dispatched']);
        $this->assertSame(25.0, $fg['final']);

        $this->expectException(ValidationException::class);
        $service->dispatch($this->fg, 30, 'Cold Chain Foods', null, null, $this->admin);
    }

    public function test_trading_goods_go_straight_from_grn_to_dispatch(): void
    {
        $gloves = InventoryItem::create(['sku' => 'Hand Gloves', 'item_type' => 'trading', 'unit' => 'BOX', 'opening_qty' => 10]);
        $this->grnReceive('Hand Gloves', 300);

        app(InventoryService::class)->dispatch($gloves, 100, 'Retail Customer', null, null, $this->admin);

        $b = $this->balance($gloves);
        $this->assertSame([10.0, 300.0, 100.0, 210.0], [$b['opening'], $b['received'], $b['dispatched'], $b['final']]);
    }

    public function test_raw_material_cannot_be_dispatched(): void
    {
        $this->expectException(ValidationException::class);
        app(InventoryService::class)->dispatch($this->compound, 1, 'Anyone', null, null, $this->admin);
    }

    public function test_screens_work_through_the_ui(): void
    {
        $this->actingAs($this->admin);

        Volt::test('inventory.production')
            ->set('fgItemId', (string) $this->fg->id)
            ->set('qty', '10')
            ->set('wastage', [$this->compound->id => '1'])
            ->call('produce')
            ->assertHasNoErrors();
        $this->assertSame(10.0, $this->balance($this->fg)['final']);

        Volt::test('inventory.dispatch')
            ->set('itemId', (string) $this->fg->id)
            ->set('qty', '4')
            ->set('customerName', 'Cold Chain Foods')
            ->call('dispatchGoods')
            ->assertHasNoErrors();
        $this->assertSame(6.0, $this->balance($this->fg)['final']);

        Volt::test('inventory.items')
            ->set('sku', 'New Trading Item')->set('itemType', 'trading')->set('unit', 'box')->set('openingQty', '5')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('inventory_items', ['sku' => 'New Trading Item', 'item_type' => 'trading', 'unit' => 'BOX']);

        $this->get(route('inventory.stock'))->assertOk()->assertSee('PCM Pouch 500g Blue')->assertSee('= Final');
    }

    public function test_shortage_message_clears_when_quantity_changes(): void
    {
        $this->actingAs($this->admin);

        Volt::test('inventory.production')
            ->set('fgItemId', (string) $this->fg->id)
            ->set('qty', '500')->call('produce')->assertHasErrors()
            ->set('qty', '20')->assertHasNoErrors();
    }

    public function test_remove_deletes_unused_items_and_archives_used_ones(): void
    {
        $this->actingAs($this->admin);
        $typo = InventoryItem::create(['sku' => 'aPouch 500gm', 'item_type' => 'pm', 'unit' => 'NOS', 'opening_qty' => 1000]);

        Volt::test('inventory.items')->call('removeItem', $typo->id);
        $this->assertDatabaseMissing('inventory_items', ['id' => $typo->id]);

        // The compound is in a recipe, so it is archived, not deleted.
        Volt::test('inventory.items')->call('removeItem', $this->compound->id);
        $this->assertFalse($this->compound->fresh()->active);
        $this->get(route('inventory.stock'))->assertOk()->assertDontSee('PCM Compound');

        Volt::test('inventory.items')->call('restoreItem', $this->compound->id);
        $this->assertTrue($this->compound->fresh()->active);
    }

    public function test_editing_an_item_clears_an_earlier_duplicate_error(): void
    {
        $this->actingAs($this->admin);

        Volt::test('inventory.items')
            ->set('sku', 'Pouch 500g')->call('save')->assertHasErrors('sku')
            ->call('edit', $this->pouch->id)->assertHasNoErrors()
            ->set('openingQty', '5')->call('save')->assertHasNoErrors();
        $this->assertSame('5.000', $this->pouch->fresh()->opening_qty);
    }

    public function test_reset_demo_command_clears_only_inventory_tables(): void
    {
        app(InventoryService::class)->produce($this->fg, 5, [], null, $this->admin);

        $this->artisan('inventory:reset-demo', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, InventoryItem::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(1, LedgerEntry::count()); // GRN stock untouched
    }

    public function test_new_sku_master_item_received_through_grn_shows_up_in_inventory_automatically(): void
    {
        \App\Models\SkuMaster::create(['sku' => 'PORM666', 'category' => 'Raw Material', 'unit' => 'kg']);
        \App\Models\SkuMaster::create(['sku' => 'PKG-BOX-01', 'category' => 'Packaging Panels', 'unit' => 'NOS']);
        $this->grnReceive('PORM666', 250);
        $this->grnReceive('GRN-ONLY-SKU', 40); // received but never added to SKU Master

        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $this->actingAs($manager)->get(route('inventory.stock'))->assertOk()->assertSee('PORM666')->assertSee('GRN-ONLY-SKU');

        $porm = InventoryItem::where('sku', 'PORM666')->firstOrFail();
        $this->assertSame(['rm', 'KG'], [$porm->item_type, $porm->unit]);
        $this->assertSame('pm', InventoryItem::where('sku', 'PKG-BOX-01')->value('item_type'));
        $this->assertSame(250.0, $this->balance($porm)['received']);
        $this->assertSame(250.0, $this->balance($porm)['final']);

        // Opening the page again never duplicates items.
        $count = InventoryItem::count();
        $this->actingAs($manager)->get(route('inventory.items'))->assertOk();
        $this->assertSame($count, InventoryItem::count());

        // Store Manager sees the Inventory section in their own sidebar.
        $this->actingAs($manager)->get(route('grn.dashboard'))->assertOk()->assertSeeInOrder(['Validation Issues', 'Inventory', 'Stock Summary', 'Create Bundle']);
    }

    public function test_store_manager_adds_sku_raises_and_releases_po_then_grn_stock_shows_in_inventory(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $vendor = \App\Models\VendorMaster::create(['vendor_name' => 'PORM Test Vendor', 'gst_number' => '27AABCP1234Q1Z5', 'contact_phone' => '+91 22 0000 0000', 'category' => 'Raw Material', 'active' => true]);
        $this->actingAs($manager);

        $this->get(route('grn.dashboard'))->assertOk()->assertSee('SKU Master');
        $this->get(route('admin.sku'))->assertOk();
        Volt::test('admin.sku-master')
            ->set('sku', 'PORM666')->set('category', 'PCM — Raw Material')->set('unit', 'KG')
            ->call('addSku')->assertHasNoErrors();
        $this->assertDatabaseHas('sku_masters', ['sku' => 'PORM666']);

        Volt::test('admin.po-master')
            ->set('purchaseNature', 'Raw Material')
            ->call('selectVendor', $vendor->vendor_name)
            ->set('poNumber', 'PO-PORM666-1')
            ->set('billingBuilding', 'Tan90 Office')->set('billingStreet', 'MIDC Road')->set('billingCity', 'Thane')
            ->set('billingState', 'Maharashtra')->set('billingZip', '400604')
            ->set('items.0.product', 'PORM666')->set('items.0.hsnSac', '3824')
            ->set('items.0.quantity', '250')->set('items.0.uom', 'KG')->set('items.0.listPrice', '40')
            ->call('saveAndSubmit')->assertHasNoErrors();
        $po = \App\Models\PurchaseOrder::where('po_number', 'PO-PORM666-1')->firstOrFail();
        Volt::test('admin.po-master')->call('releasePo', $po->id)->assertHasNoErrors();
        $this->assertNotNull($po->fresh()->released_at);

        $this->grnReceive('PORM666', 250); // what GRN posting writes after gate -> unloading -> QC

        $this->get(route('inventory.stock'))->assertOk()->assertSee('PORM666');
        $porm = InventoryItem::where('sku', 'PORM666')->firstOrFail();
        $this->assertSame('rm', $porm->item_type);
        $this->assertSame(250.0, $this->balance($porm)['final']);
    }

    public function test_only_admin_and_store_manager_can_open_inventory(): void
    {
        foreach (['inventory.stock', 'inventory.items', 'inventory.production', 'inventory.dispatch'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
            $this->actingAs(User::factory()->create(['role' => Role::StoreManager]))->get(route($route))->assertOk();
            $this->actingAs(User::factory()->create(['role' => Role::Qc]))->get(route($route))->assertForbidden();
        }
    }
}
