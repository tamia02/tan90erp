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
 * The other direction of today's sync fixes: Zoho -> Tan90 (Vendor/Item/
 * Customer master data sync) was tested and fixed earlier, but Tan90 ->
 * Zoho was never tested at all. Confirmed by inspection: no observer was
 * registered for Tan90\MasterData\Vendor/Item/Customer, so creating or
 * approving a record there pushed nothing to Zoho — only the legacy
 * VendorMaster/SkuMaster screens did. Client explicitly asked this be
 * tested. These tests cover the fix: ApprovalService::pushToZohoOnApproval(),
 * called only once a record reaches approval_status=approved (never on
 * submit/draft), for all three Zoho-representable entity types.
 */
class ApprovalZohoPushTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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

    public function test_approving_a_vendor_pushes_it_to_zoho(): void
    {
        $this->fakeZohoUpsert();
        $approver = $this->masterDataManager();
        $vendor = Vendor::factory()->create(['name' => 'Push Test Vendor Ltd', 'approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['vendors', $vendor->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $vendor->fresh()->approval_status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/contacts')
            && ($request['contact_name'] ?? null) === 'Push Test Vendor Ltd'
            && ($request['contact_type'] ?? null) === 'vendor');
    }

    public function test_approving_a_customer_pushes_it_to_zoho_as_contact_type_customer(): void
    {
        $this->fakeZohoUpsert();
        $approver = $this->masterDataManager();
        $customer = Customer::create([
            'code' => 'CU-PUSH-1', 'name' => 'Push Test Customer Pvt Ltd',
            'segment' => 'Other', 'status' => 'active', 'approval_status' => 'review',
        ]);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['customers', $customer->id]));

        $this->assertSame('approved', $customer->fresh()->approval_status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/contacts')
            && ($request['contact_name'] ?? null) === 'Push Test Customer Pvt Ltd'
            && ($request['contact_type'] ?? null) === 'customer');
    }

    public function test_approving_an_item_pushes_it_to_zoho(): void
    {
        $this->fakeZohoUpsert();
        $approver = $this->masterDataManager();
        $item = Item::factory()->create(['sku' => 'PUSH-TEST-SKU-1', 'approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['items', $item->id]));

        $this->assertSame('approved', $item->fresh()->approval_status);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/items')
            && ($request['sku'] ?? null) === 'PUSH-TEST-SKU-1');
    }

    public function test_submitting_for_review_does_not_push_to_zoho_yet(): void
    {
        $this->fakeZohoUpsert();
        $approver = $this->masterDataManager();
        $vendor = Vendor::factory()->create(['name' => 'Draft Vendor Not Pushed Yet', 'approval_status' => 'draft']);

        $this->actingAs($approver)->post(route('tan90.master-data.submit', ['vendors', $vendor->id]));

        $this->assertSame('review', $vendor->fresh()->approval_status);
        Http::assertNothingSent();
    }

    public function test_a_zoho_push_failure_does_not_block_the_local_approval(): void
    {
        Http::fake(fn () => Http::response(['code' => 5, 'message' => 'simulated failure'], 500));
        $approver = $this->masterDataManager();
        $vendor = Vendor::factory()->create(['name' => 'Resilient Vendor', 'approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['vendors', $vendor->id]));

        $this->assertSame('approved', $vendor->fresh()->approval_status);
    }
}
