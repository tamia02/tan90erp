<?php

namespace Tests\Feature;

use App\Models\Tan90\MasterData\Customer as MasterDataCustomer;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Same gap found and fixed for Vendors/Items, on the customer side: Zoho's
 * /contacts endpoint (filtered by contact_type=customer) was never synced
 * anywhere — Tan90\MasterData\Customer had no Zoho sync at all, confirmed
 * live (24 real customer contacts in the org, none reflected in the app).
 */
class ZohoCustomerMasterDataSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
    }

    private function fakeZoho(array $vendorContacts, array $customerContacts): void
    {
        Http::fake([
            '*contacts*contact_type=vendor*' => Http::response(['contacts' => $vendorContacts], 200),
            '*contacts*contact_type=customer*' => Http::response(['contacts' => $customerContacts], 200),
            '*items*' => Http::response(['items' => []], 200),
        ]);
    }

    public function test_syncing_customers_from_zoho_populates_master_data_customer(): void
    {
        $this->fakeZoho([], [[
            'contact_id' => 'zoho-cust-1',
            'contact_name' => 'Bharat Serums and Vaccines Limited',
            'gst_no' => '27AACCB1234K1Z1',
            'status' => 'active',
            'billing_address' => ['city' => 'Mumbai', 'state' => 'Maharashtra'],
            'credit_limit' => 500000,
            'payment_terms_label' => 'Net 30',
        ]]);

        $result = app(ZohoInventoryService::class)->syncMasterData(200);
        $this->assertSame(1, $result['customers']);

        $customer = MasterDataCustomer::where('name', 'Bharat Serums and Vaccines Limited')->first();
        $this->assertNotNull($customer);
        $this->assertSame('27AACCB1234K1Z1', $customer->gstin);
        $this->assertSame('Mumbai', $customer->city);
        $this->assertSame('Maharashtra', $customer->state);
        $this->assertEquals(500000, (float) $customer->credit_limit);
        $this->assertSame('Net 30', $customer->payment_terms);
        $this->assertSame('approved', $customer->approval_status);
        // tan90_customers.segment is a fixed enum with no Zoho equivalent —
        // falls back to the column's own default rather than a free-text value.
        $this->assertSame('Other', $customer->segment);
    }

    public function test_re_syncing_the_same_customer_updates_rather_than_duplicates(): void
    {
        $this->fakeZoho([], [[
            'contact_id' => 'zoho-cust-2', 'contact_name' => 'TATA 1MG Technologies Private Limited', 'status' => 'active',
        ]]);

        $service = app(ZohoInventoryService::class);
        $service->syncMasterData(200);
        $service->syncMasterData(200);

        $this->assertSame(1, MasterDataCustomer::where('name', 'TATA 1MG Technologies Private Limited')->count());
    }

    public function test_two_zoho_customer_contacts_sharing_a_name_collapse_to_one_master_data_row(): void
    {
        $this->fakeZoho([], [
            ['contact_id' => 'zoho-cust-A', 'contact_name' => 'Amazon Seller Services Private Limited', 'status' => 'active'],
            ['contact_id' => 'zoho-cust-B', 'contact_name' => 'Amazon Seller Services Private Limited', 'status' => 'active'],
        ]);

        app(ZohoInventoryService::class)->syncMasterData(200);

        $this->assertSame(1, MasterDataCustomer::where('name', 'Amazon Seller Services Private Limited')->count());
    }

    public function test_vendor_sync_is_unaffected_by_customer_sync(): void
    {
        $this->fakeZoho(
            [['contact_id' => 'zoho-v-1', 'contact_name' => 'Thermocore Materials Pvt Ltd', 'status' => 'active']],
            [['contact_id' => 'zoho-c-1', 'contact_name' => 'Booker India Limited', 'status' => 'active']],
        );

        $result = app(ZohoInventoryService::class)->syncMasterData(200);

        $this->assertSame(1, $result['vendors']);
        $this->assertSame(1, $result['customers']);
    }
}
