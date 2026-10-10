<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Store Manager's requests 2026-10-10: stock adjust on Edit, Items import CSV,
// clickable Inventory Summary, editable quantities in Create Bundle, and a
// bypass when the vendor can't accept the PO.
class StoreManagerRequests20261010Test extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private InventoryItem $rm;

    private InventoryItem $pm;

    private InventoryItem $fg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => Role::StoreManager])->fresh();
        $this->rm = InventoryItem::create(['sku' => 'Chem RM', 'code' => 'RM-1', 'name' => 'Compound', 'item_type' => 'rm', 'unit' => 'KG']);
        $this->pm = InventoryItem::create(['sku' => 'Pouch', 'code' => 'PM-1', 'name' => 'Pouch', 'item_type' => 'pm', 'unit' => 'PCS']);
        $this->fg = InventoryItem::create(['sku' => 'Pack', 'code' => 'FG-1', 'name' => 'Pack', 'item_type' => 'fg', 'unit' => 'PCS']);
        $this->fg->bomLines()->create(['component_item_id' => $this->rm->id, 'qty_per_unit' => 0.5]);
        $this->fg->bomLines()->create(['component_item_id' => $this->pm->id, 'qty_per_unit' => 1]);
        $this->actingAs($this->manager);
    }

    private function stock(InventoryItem $item): float
    {
        return app(InventoryService::class)->available($item->fresh());
    }

    public function test_edit_adjusts_stock_up_and_down_with_a_reason(): void
    {
        $page = Volt::test('inventory.items')->call('edit', $this->rm->id)->assertSee('Adjust stock');
        $page->set('adjustQty', '40')->call('save')->assertHasErrors('adjustReason');
        $page->set('adjustReason', 'Physical count')->call('save')->assertHasNoErrors()->assertSee('Saved RM-1 · Compound. Stock +40 KG.');
        $this->assertSame(40.0, $this->stock($this->rm));

        Volt::test('inventory.items')->call('edit', $this->rm->id)->set('adjustQty', '-55')->set('adjustReason', 'Damaged')->call('save')->assertHasErrors('adjustQty');
        Volt::test('inventory.items')->call('edit', $this->rm->id)->set('adjustQty', '-15')->set('adjustReason', 'Damaged')->call('save')->assertHasNoErrors();
        $this->assertSame(25.0, $this->stock($this->rm));

        Volt::test('inventory.items')->call('$set', 'historyItemId', $this->rm->id)->assertSee('Stock adjusted up')->assertSee('Physical count')->assertSee('Damaged');
    }

    public function test_inventory_summary_numbers_open_only_that_kind_of_movement(): void
    {
        app(InventoryService::class)->adjust($this->rm, 30, 'Count', $this->manager);
        app(InventoryService::class)->adjust($this->pm, 30, 'Count', $this->manager);
        app(InventoryService::class)->produce($this->fg, 10, [$this->rm->id => 1], null, $this->manager);

        Volt::test('inventory.stock')->assertSee('± Adjusted')
            ->call('showHistory', $this->rm->id, 'wastage')->assertSee('History: Compound')->assertSee('Wastage')->assertDontSee('Stock adjusted up')
            ->call('showHistory', $this->rm->id, 'adjusted')->assertSee('Stock adjusted up')->assertDontSee('Used in production')
            ->call('showHistory', $this->rm->id, '')->assertSee('Used in production')->assertSee('Stock adjusted up');
    }

    public function test_create_bundle_quantities_can_change_for_one_batch_or_become_the_recipe(): void
    {
        app(InventoryService::class)->adjust($this->rm, 100, 'Count', $this->manager);
        app(InventoryService::class)->adjust($this->pm, 100, 'Count', $this->manager);

        $page = Volt::test('inventory.production')->set('fgItemId', (string) $this->fg->id)
            ->assertSet('perUnit', [$this->rm->id => '0.5', $this->pm->id => '1']);
        $page->set('qty', '10')->set('perUnit', [$this->rm->id => '0.8', $this->pm->id => '1'])->call('produce')->assertHasNoErrors();
        $this->assertSame([92.0, 90.0, 10.0], [$this->stock($this->rm), $this->stock($this->pm), $this->stock($this->fg)]); // 0.8 x 10 used
        $this->assertSame('0.5000', (string) $this->fg->bomLines()->where('component_item_id', $this->rm->id)->value('qty_per_unit')); // recipe unchanged

        Volt::test('inventory.production')->set('fgItemId', (string) $this->fg->id)->set('qty', '10')
            ->set('perUnit', [$this->rm->id => '0.6', $this->pm->id => '1'])->set('saveAsRecipe', true)->call('produce')->assertHasNoErrors();
        $this->assertSame('0.6000', (string) $this->fg->bomLines()->where('component_item_id', $this->rm->id)->value('qty_per_unit'));
        $this->assertSame(86.0, $this->stock($this->rm));
    }

    public function test_items_import_updates_and_adds_items_and_rejects_a_bad_file_whole(): void
    {
        Volt::test('inventory.items')->call('exportItemsCsv')->assertFileDownloaded();

        $bad = "ID,SKU Code,Display Name,Type,Unit\n{$this->rm->id},RM-1,Compound X,RM,KG\n,NEW-1,New Item,Wrong,KG\n";
        Volt::test('inventory.items')->set('importFile', UploadedFile::fake()->createWithContent('i.csv', $bad))->call('importItemsCsv')
            ->assertSee('Nothing was imported')->assertSee('Row 3: Type must be');
        $this->assertSame('Compound', $this->rm->fresh()->name);

        $good = "\xEF\xBB\xBFID,SKU Code,Display Name,Type,Unit\n{$this->rm->id},RM-1,Compound X,,\n,PM-1,Pouch Blue,,\n,NEW-1,New Item,Trading,PCS\n";
        Volt::test('inventory.items')->set('importFile', UploadedFile::fake()->createWithContent('i.csv', $good))->call('importItemsCsv')
            ->assertHasNoErrors()->assertSee('Import done: 1 items added, 2 updated.');
        $this->assertSame(['Compound X', 'rm', 'KG'], [$this->rm->fresh()->name, $this->rm->fresh()->item_type, $this->rm->fresh()->unit]);
        $this->assertSame('Pouch Blue', $this->pm->fresh()->name); // matched by SKU Code
        $this->assertDatabaseHas('inventory_items', ['code' => 'NEW-1', 'name' => 'New Item', 'item_type' => 'trading', 'unit' => 'PCS']);
    }

    public function test_store_manager_can_accept_a_po_for_the_vendor(): void
    {
        $po = PurchaseOrder::factory()->create(['released_at' => now(), 'vendor_name' => 'Away Vendor']);
        $page = Volt::test('admin.po-master')->assertSee('Bypass: accept for vendor')->call('openBypass', $po->id);
        $page->call('bypassVendorAcceptance')->assertHasErrors('bypassReason');
        $page->set('bypassReason', 'Vendor confirmed on phone')->call('bypassVendorAcceptance')->assertHasNoErrors();

        $ack = PurchaseOrderAcknowledgement::where('po_number', $po->po_number)->firstOrFail();
        $this->assertTrue($ack->accepted);
        $this->assertSame($this->manager->id, $ack->acknowledged_by);
        $this->assertStringContainsString('Vendor confirmed on phone', $ack->remarks);
    }
}
