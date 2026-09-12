<?php

namespace Tests\Feature;

use App\Models\Tan90\MasterData\Customer as MasterDataCustomer;
use App\Models\Tan90\MasterData\Vendor as MasterDataVendor;
use App\Models\VendorMaster;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reproduces a bug found during a live push against the real Zoho account:
 * Zoho rejects gst_no with error code 8 ("Invalid Element gst_no") unless
 * gst_treatment is sent alongside it — the code sent gst_no alone, so every
 * vendor/customer/legacy-vendor WITH a real, valid GSTIN silently failed to
 * push, while ones with no GST at all succeeded. Confirmed live: a test
 * vendor with a valid GSTIN failed with that exact error; the same vendor
 * with no GSTIN pushed successfully and was verified to actually appear in
 * the real Zoho Inventory account (contact_id 3947444000000651267, since
 * deleted as test cleanup).
 */
class ZohoGstTreatmentPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.zoho.inventory.sync_enabled', true);
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
    }

    private function fakeNoExistingContactThenCreate(): void
    {
        Http::fake([
            '*/contacts?*' => Http::response(['contacts' => []], 200),
            '*/contacts' => Http::response(['code' => 0, 'contact' => ['contact_id' => 'zoho-new-1']], 200),
        ]);
    }

    public function test_master_data_vendor_push_includes_gst_treatment_when_gst_is_valid(): void
    {
        $this->fakeNoExistingContactThenCreate();

        $vendor = MasterDataVendor::factory()->create(['gstin' => '27AACCH1234K1Z5']);

        $result = app(ZohoInventoryService::class)->pushMasterDataVendor($vendor);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/contacts')
                && ($request['gst_no'] ?? null) === '27AACCH1234K1Z5'
                && ($request['gst_treatment'] ?? null) === 'business_gst';
        });
    }

    public function test_master_data_vendor_push_omits_both_gst_fields_when_gst_is_blank(): void
    {
        $this->fakeNoExistingContactThenCreate();

        $vendor = MasterDataVendor::factory()->create(['gstin' => null]);

        $result = app(ZohoInventoryService::class)->pushMasterDataVendor($vendor);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/contacts')
                && ! array_key_exists('gst_no', $request->data())
                && ! array_key_exists('gst_treatment', $request->data());
        });
    }

    public function test_master_data_customer_push_includes_gst_treatment_when_gst_is_valid(): void
    {
        $this->fakeNoExistingContactThenCreate();

        $customer = MasterDataCustomer::create([
            'code' => 'CUST-TEST-001', 'name' => 'Test Retail Customer', 'gstin' => '29AABCU9603R1ZM',
        ]);

        $result = app(ZohoInventoryService::class)->pushMasterDataCustomer($customer);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return ($request['gst_no'] ?? null) === '29AABCU9603R1ZM'
                && ($request['gst_treatment'] ?? null) === 'business_gst';
        });
    }

    public function test_legacy_vendor_master_push_includes_gst_treatment_when_gst_is_valid(): void
    {
        $this->fakeNoExistingContactThenCreate();

        $vendor = VendorMaster::factory()->create(['gst_number' => '07AACCT9090K1Z2']);

        $result = app(ZohoInventoryService::class)->pushVendorContact($vendor);

        $this->assertTrue($result);
        Http::assertSent(function ($request) {
            return ($request['gst_no'] ?? null) === '07AACCT9090K1Z2'
                && ($request['gst_treatment'] ?? null) === 'business_gst';
        });
    }
}
