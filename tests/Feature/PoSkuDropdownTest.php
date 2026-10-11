<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SkuMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-11 client request: SKU is a searchable dropdown showing only the PO
// type's items (RM PO -> RM SKUs), HSN fills from the SKU, PDF instead of Excel.
class PoSkuDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        SkuMaster::create(['sku' => 'N-Tetradecane', 'category' => 'Zoho — Raw Material (RM)', 'unit' => 'LTR', 'zoho_hsn' => '29011000', 'active' => true]);
        SkuMaster::create(['sku' => 'Blue Panel Box', 'category' => 'Zoho — Packaging Material (PM)', 'unit' => 'NOS', 'zoho_hsn' => '48191010', 'active' => true]);
        SkuMaster::create(['sku' => 'Cooler Resale', 'category' => 'Zoho — Trading Good', 'unit' => 'PCS', 'zoho_hsn' => '84183090', 'active' => true]);
        SkuMaster::create(['sku' => 'Old RM Inactive', 'category' => 'Zoho — Raw Material (RM)', 'unit' => 'KG', 'active' => false]);
    }

    public function test_sku_list_follows_the_po_type(): void
    {
        Volt::test('admin.po-master')->set('adding', true)->set('purchaseNature', 'Raw Material')
            ->assertSee('N-Tetradecane')->assertDontSee('Blue Panel Box')->assertDontSee('Cooler Resale')->assertDontSee('Old RM Inactive')
            ->set('purchaseNature', 'PM Packaging Material')->assertSee('Blue Panel Box')->assertDontSee('N-Tetradecane')
            ->set('purchaseNature', 'Trading')->assertSee('Cooler Resale')->assertDontSee('Blue Panel Box');
    }

    public function test_picking_a_sku_fills_hsn_and_unit(): void
    {
        Volt::test('admin.po-master')->set('purchaseNature', 'Raw Material')
            ->set('items.0.product', 'N-Tetradecane')
            ->assertSet('items.0.hsnSac', '29011000')->assertSet('items.0.uom', 'LITER');
    }

    public function test_po_list_offers_pdf_not_excel(): void
    {
        \App\Models\PurchaseOrder::factory()->create(['po_number' => 'PORM26270901']);

        Volt::test('admin.po-master')->assertSee('Download PDF')->assertDontSee('Download Excel');
    }
}
