<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorStockUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Reproduces the reported bug: the SKU dropdown on Stock Update only ever
 * showed materials for POs discovered via the vendor's VendorSubmission rows
 * (a separate document-upload flow) rather than POs matched directly by
 * vendor_name — with only 1 VendorSubmission existing system-wide, every
 * vendor without one saw an empty dropdown and could never save a stock
 * update at all ("not working" / "data not showing").
 */
class VendorStockUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_with_an_open_po_but_no_vendor_submission_can_still_update_stock(): void
    {
        $vendor = User::factory()->create(['name' => 'Fresh Vendor With No Submissions', 'role' => 'vendor']);
        $po = PurchaseOrder::factory()->create(['vendor_name' => 'Fresh Vendor With No Submissions']);
        $po->lines()->create(['product' => 'Test Raw Material', 'quantity' => 100, 'list_price' => 10]);

        $this->actingAs($vendor);

        Volt::test('vendor.stock-update')
            ->assertSee('Test Raw Material')
            ->set('material', 'Test Raw Material')
            ->set('quantity', 50)
            ->set('unit', 'KG')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vendor_stock_updates', [
            'vendor_name' => 'Fresh Vendor With No Submissions',
            'material' => 'Test Raw Material',
            'quantity' => 50,
        ]);
    }
}
