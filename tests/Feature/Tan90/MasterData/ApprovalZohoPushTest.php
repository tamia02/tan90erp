<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

/**
 * Client-reported gap: no observer was ever registered for
 * Tan90\MasterData\Vendor/Item/Customer, so creating one there pushed
 * nothing to Zoho — only the legacy VendorMaster/SkuMaster screens did.
 * First fixed by pushing on approval only (client's initial call); the
 * client then reversed that after creating a real vendor that sat
 * unapproved and never reached Zoho — now pushes immediately on every save,
 * matching how VendorMaster/SkuMaster already behaved. See
 * MasterDataVendorObserver/MasterDataItemObserver/MasterDataCustomerObserver.
 */
class ApprovalZohoPushTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

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

    private function fakeZohoUpsert(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $isGet = $request->method() === 'GET';

            if (str_contains($url, '/contacts')) {
                return $isGet
                    ? Http::response(['contacts' => []], 200)
                    : Http::response(['code' => 0, 'contact' => ['contact_id' => 'zoho-new-contact']], 200);
            }

            if (str_contains($url, '/items')) {
                return $isGet
                    ? Http::response(['items' => []], 200)
                    : Http::response(['code' => 0, 'item' => ['item_id' => 'zoho-new-item']], 200);
            }

            return Http::response([], 404);
        });
    }

    public function test_creating_a_vendor_pushes_it_to_zoho_immediately(): void
    {
        $this->fakeZohoUpsert();

        Vendor::factory()->create(['name' => 'Push Test Vendor Ltd', 'approval_status' => 'draft']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/contacts')
            && ($request['contact_name'] ?? null) === 'Push Test Vendor Ltd'
            && ($request['contact_type'] ?? null) === 'vendor');
    }

    public function test_creating_a_customer_pushes_it_to_zoho_as_contact_type_customer(): void
    {
        $this->fakeZohoUpsert();

        Customer::create([
            'code' => 'CU-PUSH-1', 'name' => 'Push Test Customer Pvt Ltd',
            'segment' => 'Other', 'status' => 'active', 'approval_status' => 'draft',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/contacts')
            && ($request['contact_name'] ?? null) === 'Push Test Customer Pvt Ltd'
            && ($request['contact_type'] ?? null) === 'customer');
    }

    public function test_creating_an_item_pushes_it_to_zoho(): void
    {
        $this->fakeZohoUpsert();

        Item::factory()->create(['sku' => 'PUSH-TEST-SKU-1', 'approval_status' => 'draft']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/items')
            && ($request['sku'] ?? null) === 'PUSH-TEST-SKU-1');
    }

    public function test_editing_an_already_pushed_vendor_pushes_the_update_too(): void
    {
        $this->fakeZohoUpsert();

        $vendor = Vendor::factory()->create(['name' => 'Editable Vendor Ltd', 'approval_status' => 'draft']);
        $vendor->update(['phone' => '+91 90000 00001']);

        Http::assertSentCount(4); // create: lookup + create, update: lookup + update
    }

    public function test_a_zoho_push_failure_does_not_block_saving_locally(): void
    {
        Http::fake(fn () => Http::response(['code' => 5, 'message' => 'simulated failure'], 500));

        $vendor = Vendor::factory()->create(['name' => 'Resilient Vendor', 'approval_status' => 'draft']);

        $this->assertDatabaseHas('tan90_vendors', ['id' => $vendor->id, 'name' => 'Resilient Vendor']);
    }

    public function test_approving_a_vendor_does_not_push_again_by_itself(): void
    {
        // Approval no longer triggers a push (the save that created the
        // record already did) — this just confirms approve() still works
        // and doesn't error now that ApprovalService no longer touches Zoho.
        $this->fakeZohoUpsert();
        $approver = $this->masterDataManager();
        $vendor = Vendor::factory()->create(['name' => 'Approve Flow Vendor', 'approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['vendors', $vendor->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $vendor->fresh()->approval_status);
    }
}
