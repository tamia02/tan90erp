<?php

namespace Tests\Feature;

use App\Models\Tan90\MasterData\Vendor as MasterDataVendor;
use App\Models\VendorMaster;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reproduces the reported bug: VendorMaster (what Zoho sync writes to) and
 * Tan90\MasterData\Vendor (the separate "Master Data > Vendors" screen)
 * were two entirely disconnected tables — a sync pulling real data from
 * Zoho into one left the other showing unrelated, never-synced demo rows.
 */
class ZohoVendorMasterDataSyncTest extends TestCase
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

    public function test_syncing_vendors_from_zoho_populates_both_vendor_master_and_master_data_vendor(): void
    {
        Http::fake([
            '*contacts*' => Http::response([
                'contacts' => [[
                    'contact_id' => 'zoho-9001',
                    'contact_name' => 'Hindustan Chemical Corporation',
                    'gst_no' => '27AAAFH1234K1Z9',
                    'status' => 'active',
                    'contact_persons' => [['first_name' => 'Raj', 'phone' => '9998887770', 'email' => 'raj@hcc.example']],
                ]],
            ], 200),
            '*items*' => Http::response(['items' => []], 200),
        ]);

        $result = app(ZohoInventoryService::class)->syncMasterData(200);
        $this->assertSame(1, $result['vendors']);

        $vendorMaster = VendorMaster::where('vendor_name', 'Hindustan Chemical Corporation')->first();
        $this->assertNotNull($vendorMaster);
        $this->assertSame('27AAAFH1234K1Z9', $vendorMaster->gst_number);

        $masterDataVendor = MasterDataVendor::where('name', 'Hindustan Chemical Corporation')->first();
        $this->assertNotNull($masterDataVendor);
        $this->assertSame('ZOHO-HINDUSTAN-CHEMICAL-CORPORATION', $masterDataVendor->code);
        $this->assertSame('27AAAFH1234K1Z9', $masterDataVendor->gstin);
        $this->assertSame('verified', $masterDataVendor->gst_status);
        $this->assertSame('approved', $masterDataVendor->approval_status);
        $this->assertSame('raj@hcc.example', $masterDataVendor->email);
    }

    public function test_re_syncing_the_same_vendor_updates_rather_than_duplicates(): void
    {
        Http::fake([
            '*contacts*' => Http::response([
                'contacts' => [[
                    'contact_id' => 'zoho-9002',
                    'contact_name' => 'Sharp Polymers',
                    'gst_no' => '33AAEFS4455A1ZX',
                    'status' => 'active',
                ]],
            ], 200),
            '*items*' => Http::response(['items' => []], 200),
        ]);

        $service = app(ZohoInventoryService::class);
        $service->syncMasterData(200);
        $service->syncMasterData(200);

        $this->assertSame(1, MasterDataVendor::where('name', 'Sharp Polymers')->count());
    }

    /**
     * The exact scenario found live: the Zoho org has several distinct
     * contact records sharing the same name. VendorMaster already collapses
     * these into one row by name; Master Data must do the same rather than
     * creating a separate row per contact_id, which produced four "Tan90
     * Demo Vendor" rows in production before this fix.
     */
    public function test_two_zoho_contacts_sharing_a_name_collapse_to_one_master_data_row(): void
    {
        Http::fake([
            '*contacts*' => Http::response([
                'contacts' => [
                    ['contact_id' => 'zoho-A', 'contact_name' => 'Tan90 Demo Vendor', 'status' => 'active'],
                    ['contact_id' => 'zoho-B', 'contact_name' => 'Tan90 Demo Vendor', 'status' => 'active'],
                ],
            ], 200),
            '*items*' => Http::response(['items' => []], 200),
        ]);

        app(ZohoInventoryService::class)->syncMasterData(200);

        $this->assertSame(1, VendorMaster::where('vendor_name', 'Tan90 Demo Vendor')->count());
        $this->assertSame(1, MasterDataVendor::where('name', 'Tan90 Demo Vendor')->count());
    }
}
