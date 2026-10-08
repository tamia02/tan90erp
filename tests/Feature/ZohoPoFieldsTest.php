<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorMaster;
use App\Models\ZohoFieldDefinition;
use App\Services\Zoho\ZohoFieldPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ZohoPoFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function zohoPoDefinitions(): void
    {
        ZohoFieldDefinition::create(['entity' => 'purchaseorder', 'api_name' => 'cf_po_valid_upto', 'label' => 'PO Valid Upto', 'data_type' => 'date', 'is_mandatory' => true, 'sort_order' => 1]);
        ZohoFieldDefinition::create(['entity' => 'purchaseorder', 'api_name' => 'cf_purchase_nature', 'label' => 'Purchase Nature', 'data_type' => 'dropdown', 'is_mandatory' => true, 'options' => ['Inward Stock', 'Direct Sale', 'Fixed Asset - R&D'], 'sort_order' => 2]);
        ZohoFieldDefinition::create(['entity' => 'purchaseorder', 'api_name' => 'cf_fulfilment_stage', 'label' => 'Fulfilment Stage', 'data_type' => 'dropdown', 'is_mandatory' => false, 'options' => ['WIP', 'RTD', 'Closed'], 'sort_order' => 3]);
    }

    private function poForm()
    {
        $vendor = VendorMaster::create(['vendor_name' => 'Zoho Field Vendor', 'gst_number' => '27AABCZ1234Q1Z5', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]);
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));

        return Volt::test('admin.po-master')
            ->set('purchaseNature', 'Raw Material')
            ->call('selectVendor', $vendor->vendor_name)
            ->set('poNumber', 'PO-ZF-'.random_int(1000, 9999))
            ->set('billingBuilding', 'B')->set('billingStreet', 'S')->set('billingCity', 'Thane')->set('billingState', 'Maharashtra')->set('billingZip', '400604')
            ->set('items.0.product', 'PCM Solution')->set('items.0.quantity', '10')->set('items.0.uom', 'KG')->set('items.0.listPrice', '5');
    }

    public function test_zoho_required_po_fields_are_enforced_and_saved(): void
    {
        $this->zohoPoDefinitions();

        $this->poForm()->call('saveAndSubmit')
            ->assertHasErrors(['validUpto' => 'required', 'zohoFields.cf_purchase_nature' => 'required'])
            ->assertHasNoErrors('zohoFields.cf_fulfilment_stage');
        $this->assertSame(0, PurchaseOrder::count());

        $this->poForm()->set('validUpto', now()->addMonth()->toDateString())
            ->set('zohoFields.cf_purchase_nature', 'Not a Zoho option')->call('saveAndSubmit')
            ->assertHasErrors(['zohoFields.cf_purchase_nature']);

        $this->poForm()->set('validUpto', now()->addMonth()->toDateString())
            ->set('zohoFields.cf_purchase_nature', 'Fixed Asset - R&D')->set('zohoFields.cf_fulfilment_stage', 'WIP')
            ->call('saveAndSubmit')->assertHasNoErrors();
        $this->assertSame(['cf_purchase_nature' => 'Fixed Asset - R&D', 'cf_fulfilment_stage' => 'WIP'], PurchaseOrder::first()->zoho_custom_fields);
    }

    public function test_form_shows_zoho_fields_with_required_marks(): void
    {
        $this->zohoPoDefinitions();

        $this->poForm()->call('openForm')->assertSee('Zoho Inventory fields')->assertSee('Purchase Nature *')->assertSee('PO Valid Upto *')
            ->assertSee('Fixed Asset - R&amp;D', false)->assertSee('Fulfilment Stage');
    }

    public function test_without_zoho_definitions_po_form_works_as_before(): void
    {
        $this->poForm()->call('saveAndSubmit')->assertHasNoErrors()->assertDontSee('Zoho Inventory fields');
        $this->assertNull(PurchaseOrder::first()->zoho_custom_fields);
    }

    public function test_field_pull_stores_definitions_and_switches_off_removed_ones(): void
    {
        config(['services.zoho_items_pull' => ['organization_id' => '6', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1', 'schedule_enabled' => false]]);
        ZohoFieldDefinition::create(['entity' => 'purchaseorder', 'api_name' => 'cf_old', 'label' => 'Old', 'data_type' => 'string']);
        Http::fake([
            'accounts.zoho.test/*' => Http::response(['access_token' => 't']),
            'zoho.test/inventory/v1/settings/customfields*' => Http::response(['customfields' => [
                'purchaseorder' => [['api_name' => 'cf_purchase_nature', 'label' => 'Purchase Nature', 'data_type' => 'dropdown', 'is_mandatory' => true, 'is_active' => true,
                    'values' => [['name' => 'Sample', 'order' => 2, 'is_active' => true], ['name' => 'Inward Stock', 'order' => 1, 'is_active' => true], ['name' => 'Gone', 'order' => 3, 'is_active' => false]]]],
                'creditnote' => [],
            ]]),
        ]);

        $stats = app(ZohoFieldPullService::class)->pull();

        $this->assertSame([1, 1, 1], [$stats['fields'], $stats['mandatory'], $stats['deactivated']]);
        $this->assertSame(['Inward Stock', 'Sample'], ZohoFieldDefinition::where('api_name', 'cf_purchase_nature')->value('options'));
        $this->assertFalse(ZohoFieldDefinition::where('api_name', 'cf_old')->value('active'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_contains($r->url(), 'oauth'));
    }
}
