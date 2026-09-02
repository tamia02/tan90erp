<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\SupplierClaim;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Not RefreshDatabase: relies on the persistent demo seed (vendor@tan90.test
 * matches PO RM 2627 0020's vendor_name "Thermocore Materials Pvt Ltd"),
 * matching the pattern Space100PercentVerificationTest already established
 * for this same vendor.submissions page.
 */
class VendorPortalTest extends TestCase
{
    public function test_vendor_can_accept_a_purchase_order(): void
    {
        $vendor = User::where('email', 'vendor@tan90.test')->firstOrFail();
        $po = PurchaseOrder::where('po_number', 'PO RM 2627 0020')->firstOrFail();
        PurchaseOrderAcknowledgement::where('po_number', $po->po_number)->delete();

        $this->actingAs($vendor);

        Volt::test('vendor.purchase-orders')
            ->assertSee($po->po_number)
            ->call('acknowledge', $po->po_number, true)
            ->assertHasNoErrors();

        $ack = PurchaseOrderAcknowledgement::where('po_number', $po->po_number)->firstOrFail();
        $this->assertTrue($ack->accepted);
        $this->assertSame($vendor->name, $ack->vendor_name);

        PurchaseOrderAcknowledgement::where('po_number', $po->po_number)->delete();
    }

    public function test_a_vendor_cannot_acknowledge_another_vendors_purchase_order(): void
    {
        $vendor = User::where('email', 'vendor@tan90.test')->firstOrFail();
        $otherPo = PurchaseOrder::where('vendor_name', '!=', $vendor->name)->firstOrFail();

        $this->actingAs($vendor);

        // acknowledge()'s own vendor-scoped firstOrFail() is the guard being
        // tested here — Volt::test() propagates it as a real exception rather
        // than converting it to an inspectable HTTP response, so assert on
        // the exception directly instead of a status code.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Volt::test('vendor.purchase-orders')->call('acknowledge', $otherPo->po_number, true);
    }

    public function test_vendor_can_submit_and_view_a_claim(): void
    {
        $vendor = User::where('email', 'vendor@tan90.test')->firstOrFail();

        $this->actingAs($vendor);

        Volt::test('vendor.claims')
            ->set('description', 'Received 5 damaged units in the last shipment.')
            ->call('submit')
            ->assertHasNoErrors();

        $claim = SupplierClaim::where('vendor_name', $vendor->name)->latest()->firstOrFail();
        $this->assertSame('open', $claim->status);

        Volt::test('vendor.claims')->assertSee('Received 5 damaged units');

        $claim->delete();
    }

    public function test_staff_can_resolve_a_supplier_claim(): void
    {
        $admin = User::where('email', 'admin@tan90.test')->firstOrFail();
        $claim = SupplierClaim::create([
            'vendor_name' => 'Test Vendor Co', 'description' => 'Short delivery on last order.', 'status' => 'open',
        ]);

        $this->actingAs($admin);

        Volt::test('admin.supplier-claims')
            ->call('respond', $claim->id)
            ->set('resolutionNotes', 'Confirmed and credited.')
            ->call('resolve', $claim->id, 'resolved')
            ->assertHasNoErrors();

        $claim->refresh();
        $this->assertSame('resolved', $claim->status);
        $this->assertSame('Confirmed and credited.', $claim->resolution_notes);
        $this->assertNotNull($claim->resolved_at);

        $claim->delete();
    }
}
