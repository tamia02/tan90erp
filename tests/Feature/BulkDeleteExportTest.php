<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;
use ZipArchive;

// 2026-10-13 client meeting: bulk delete on master lists (never losing history)
// and export with several filters + chosen columns.
class BulkDeleteExportTest extends TestCase
{
    use RefreshDatabase;

    private function sheet($response): string
    {
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $response->effects['download']['content'] ? base64_decode($response->effects['download']['content']) : '');
        $zip = new ZipArchive;
        $zip->open($file);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($file);

        return $xml;
    }

    public function test_items_bulk_delete_keeps_items_with_history(): void
    {
        $this->actingAs($user = User::factory()->create(['role' => Role::StoreManager]));
        $unused = InventoryItem::create(['sku' => 'Unused RM', 'item_type' => 'rm', 'unit' => 'KG']);
        $used = InventoryItem::create(['sku' => 'Used RM', 'item_type' => 'rm', 'unit' => 'KG']);
        InventoryMovement::create(['item_id' => $used->id, 'type' => 'adjust_in', 'qty' => 5, 'created_by' => $user->id]);

        Volt::test('inventory.items')->set('selected', [(string) $unused->id, (string) $used->id])
            ->assertSee('2 selected')->call('bulkRemove')->assertSee('1 deleted, 1 archived');
        $this->assertNull(InventoryItem::find($unused->id));
        $this->assertFalse((bool) $used->fresh()->active, 'archived, history kept');
    }

    public function test_items_export_uses_filters_and_columns(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        InventoryItem::create(['sku' => 'Export RM', 'name' => 'Export RM Name', 'item_type' => 'rm', 'unit' => 'KG']);
        InventoryItem::create(['sku' => 'Export PM', 'name' => 'Export PM Name', 'item_type' => 'pm', 'unit' => 'PCS']);

        $page = Volt::test('inventory.items')->set('exportTypes', ['pm'])->set('exportColumns', ['name', 'unit'])->call('exportFiltered');
        $xml = $this->sheet($page);
        $this->assertStringContainsString('Export PM Name', $xml);
        $this->assertStringNotContainsString('Export RM Name', $xml);
        $this->assertStringContainsString('Display Name', $xml);
        $this->assertStringNotContainsString('Stock (final)', $xml);
    }

    public function test_vendors_bulk_delete_skips_vendors_used_on_pos_and_export_filters(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]));
        $free = VendorMaster::create(['vendor_name' => 'Free Vendor', 'gst_number' => 'A', 'contact_phone' => '1', 'category' => 'Raw Material', 'active' => true, 'address_city' => 'Thane']);
        $busy = VendorMaster::create(['vendor_name' => 'Busy Vendor', 'gst_number' => 'B', 'contact_phone' => '1', 'category' => 'Packaging', 'active' => true]);
        PurchaseOrder::factory()->create(['vendor_name' => 'Busy Vendor']);

        $xml = $this->sheet(Volt::test('admin.vendor-master')->set('exportAddress', 'with')->set('exportColumns', ['vendor_name', 'address_city'])->call('exportFiltered'));
        $this->assertStringContainsString('Free Vendor', $xml);
        $this->assertStringNotContainsString('Busy Vendor', $xml);

        Volt::test('admin.vendor-master')->set('selected', [(string) $free->id, (string) $busy->id])->call('bulkDelete')
            ->assertSee('1 vendors deleted')->assertSee('Busy Vendor');
        $this->assertNull(VendorMaster::find($free->id));
        $this->assertNotNull(VendorMaster::find($busy->id));
    }
}
