<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Tan90\MasterData\Customer;
use App\Models\User;
use App\Models\VendorMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-10: PO form showed "City not mapped" - Zoho's contact list has no
// address, so none were ever imported. Picking a vendor / customer now asks
// Zoho (GET only) once; an address typed on a PO is kept for the next one.
class ContactAddressFromZohoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zoho_items_pull' => [
            'organization_id' => '1', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1', 'address_pull_enabled' => true,
        ]]);
        Http::fake([
            'accounts.zoho.test/*' => Http::response(['access_token' => 't']),
            'zoho.test/inventory/v1/contacts/333*' => Http::response(['contact' => ['billing_address' => ['address' => str_repeat('Dag no. 775, ', 60), 'city' => 'Hooghly', 'zip' => '712311']]]),
            'zoho.test/inventory/v1/contacts/111*' => Http::response(['contact' => ['billing_address' => ['attention' => 'Unit 4', 'address' => 'MIDC Road', 'street2' => 'Phase 2', 'city' => 'Thane', 'state' => 'Maharashtra', 'zip' => '400604', 'country' => 'India']]]),
            'zoho.test/inventory/v1/contacts/222*' => Http::response(['contact' => ['billing_address' => [], 'shipping_address' => ['address' => 'Plot 9', 'city' => 'Pune', 'state' => 'Maharashtra', 'zip' => '411001']]]),
            'zoho.test/inventory/v1/contacts*' => fn ($request) => Http::response(['contacts' => [
                ['contact_name' => 'Acme Chemicals', 'contact_id' => '111'], ['contact_name' => 'Fresh Dairy', 'contact_id' => '222'], ['contact_name' => 'Long Street Ltd', 'contact_id' => '333'],
            ]]),
        ]);
    }

    public function test_picking_a_vendor_with_no_address_fills_it_from_zoho(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $vendor = VendorMaster::create(['vendor_name' => 'Acme Chemicals', 'gst_number' => '27AABCP1234Q1Z5', 'contact_phone' => '1', 'category' => 'Raw Material', 'active' => true]);

        Volt::test('admin.po-master')->call('selectVendor', 'Acme Chemicals')
            ->assertSet('billingCity', 'Thane')->assertSet('billingZip', '400604')->assertSet('billingStreet', 'MIDC Road Phase 2');

        $vendor->refresh();
        $this->assertSame('Thane', $vendor->address_city);
        $this->assertNotNull($vendor->zoho_address_checked_at);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_contains($request->url(), 'oauth'));
    }

    public function test_picking_a_customer_as_delivery_fills_the_full_address(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        Customer::create(['code' => 'ZC-FRESH', 'name' => 'Fresh Dairy', 'segment' => 'Dairy', 'status' => 'active', 'approval_status' => 'approved']);

        Volt::test('admin.po-master')->set('warehouseLocation', 'Fresh Dairy')
            ->assertSet('shippingStreet', 'Plot 9')->assertSet('shippingCity', 'Pune')->assertSet('shippingZip', '411001');
    }

    public function test_nightly_command_fills_and_marks_contacts(): void
    {
        VendorMaster::create(['vendor_name' => 'Acme Chemicals', 'gst_number' => 'X', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]);
        VendorMaster::create(['vendor_name' => 'Not In Zoho', 'gst_number' => 'Y', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]);
        VendorMaster::create(['vendor_name' => 'Long Street Ltd', 'gst_number' => 'Z', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]);

        $this->artisan('zoho:pull-contact-addresses')->assertSuccessful();

        $this->assertSame('Thane', VendorMaster::where('vendor_name', 'Acme Chemicals')->value('address_city'));
        // A street longer than the column is cut to fit instead of failing (Zoho has 500+ character ones).
        $this->assertSame('Hooghly', VendorMaster::where('vendor_name', 'Long Street Ltd')->value('address_city'));
        // Asked once and marked, so the next night skips it.
        $this->assertNotNull(VendorMaster::where('vendor_name', 'Not In Zoho')->value('zoho_address_checked_at'));
        $this->artisan('zoho:pull-contact-addresses')->expectsOutputToContain('0 vendors')->assertSuccessful();
    }

    public function test_switched_off_nothing_is_asked_from_zoho_but_typed_address_is_kept(): void
    {
        config(['services.zoho_items_pull.address_pull_enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        VendorMaster::create(['vendor_name' => 'Acme Chemicals', 'gst_number' => '27AABCP1234Q1Z5', 'contact_phone' => '1', 'category' => 'Raw Material', 'active' => true]);
        \App\Models\SkuMaster::create(['sku' => 'Addr RM', 'category' => 'PCM — Raw Material', 'unit' => 'KG']);

        Volt::test('admin.po-master')->set('purchaseNature', 'Raw Material')->call('selectVendor', 'Acme Chemicals')
            ->assertSet('billingCity', '')
            ->set('billingBuilding', 'Unit 7')->set('billingStreet', 'Station Road')->set('billingCity', 'Vapi')
            ->set('billingState', 'Gujarat')->set('billingZip', '396195')
            ->set('items.0.product', 'Addr RM')->set('items.0.hsnSac', '3824')
            ->set('items.0.quantity', '10')->set('items.0.uom', 'KG')->set('items.0.listPrice', '40')
            ->call('saveAndSubmit')->assertHasNoErrors();

        Http::assertNothingSent();
        $this->assertSame('Vapi', VendorMaster::where('vendor_name', 'Acme Chemicals')->value('address_city'));
        // Next PO for this vendor fills it.
        Volt::test('admin.po-master')->call('selectVendor', 'Acme Chemicals')->assertSet('billingCity', 'Vapi')->assertSet('billingZip', '396195');
        $this->artisan('zoho:pull-contact-addresses')->assertFailed();
    }

    public function test_pickers_hide_vendors_and_customers_without_an_address(): void
    {
        config(['services.zoho_items_pull.address_pull_enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        VendorMaster::create(['vendor_name' => 'Has Address Co', 'gst_number' => 'A', 'contact_phone' => '1', 'category' => 'RM', 'active' => true, 'address_city' => 'Thane', 'address_zip' => '400604']);
        VendorMaster::create(['vendor_name' => 'No Address Co', 'gst_number' => 'B', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]);
        Customer::create(['code' => 'ZC-HAS', 'name' => 'Customer With City', 'segment' => 'Other', 'city' => 'Pune', 'status' => 'active', 'approval_status' => 'approved']);
        Customer::create(['code' => 'ZC-NO', 'name' => 'Customer Without City', 'segment' => 'Other', 'status' => 'active', 'approval_status' => 'approved']);

        Volt::test('admin.po-master')->set('adding', true)->set('vendorSearch', 'Co')
            ->assertSee('Has Address Co')->assertDontSee('No Address Co')
            ->set('warehouseSearch', 'Customer')->assertSee('Customer With City')->assertDontSee('Customer Without City')
            ->assertDontSee('City not mapped');
    }
}
