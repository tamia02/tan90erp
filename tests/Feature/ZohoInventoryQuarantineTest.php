<?php

namespace Tests\Feature;

use App\Models\VendorMaster;
use App\Models\ZohoEntityLink;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RefreshDatabase, not DatabaseTransactions: pushOperationalData() sweeps every
 * VendorMaster row, so this test needs a guaranteed-empty vendors table, not just
 * isolation from its own writes — a transaction alone leaves pre-existing seeded
 * demo vendors visible and blows the fixed Http::fake() response count.
 */
class ZohoInventoryQuarantineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        config()->set('services.zoho.inventory.rate_limit.per_run', 120);
        config()->set('services.zoho.inventory.rate_limit.per_day', 800);
        config()->set('services.zoho.inventory.max_record_failures', 2);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
    }

    public function test_a_failed_lookup_does_not_fall_through_to_creating_a_duplicate(): void
    {
        $vendor = VendorMaster::create(['vendor_name' => 'Lookup Fails Co', 'gst_number' => '', 'contact_phone' => '', 'category' => 'Test']);

        Http::fake([
            '*contacts*' => Http::response(['message' => 'internal error'], 500),
        ]);

        $result = app(ZohoInventoryService::class)->pushVendorContact($vendor);

        $this->assertFalse($result);
        // Only the failed GET lookup should have been sent — never a POST to create,
        // since the lookup failing must not be read as "doesn't exist yet".
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/contacts'));
    }

    public function test_a_record_is_quarantined_after_its_failure_budget_and_then_skipped(): void
    {
        $vendor = VendorMaster::create(['vendor_name' => 'Always Rejected Co', 'gst_number' => '', 'contact_phone' => '', 'category' => 'Test']);

        Http::fake([
            '*contacts*' => Http::sequence()
                // Each pushOperationalData() call: one GET lookup (finds nothing), one
                // POST create (Zoho rejects the content — a permanent failure, not a
                // transient one, so it must count toward quarantine).
                ->push(['contacts' => []], 200)
                ->push(['code' => 8, 'message' => 'Invalid Element gst_no'], 400)
                ->push(['contacts' => []], 200)
                ->push(['code' => 8, 'message' => 'Invalid Element gst_no'], 400),
        ]);

        $service = app(ZohoInventoryService::class);

        $first = $service->pushOperationalData(200);
        $this->assertSame(1, $first['failed']);

        $link = ZohoEntityLink::where('syncable_type', $vendor->getMorphClass())
            ->where('syncable_id', $vendor->id)
            ->where('zoho_module', 'vendors')
            ->first();
        $this->assertNotNull($link);
        $this->assertSame(1, $link->failure_count);
        $this->assertNull($link->quarantined_at);

        $second = $service->pushOperationalData(200);
        $this->assertSame(1, $second['failed']);

        $link->refresh();
        $this->assertSame(2, $link->failure_count);
        $this->assertNotNull($link->quarantined_at);

        // Third run: the record is quarantined, so it must not be attempted at all —
        // no further HTTP calls for it, and it no longer counts as "failed".
        Http::fake(['*contacts*' => Http::response(['contacts' => []], 200)]);
        $third = $service->pushOperationalData(200);
        $this->assertSame(0, $third['failed']);
        Http::assertNothingSent();
    }

    public function test_a_success_clears_a_prior_failure_count(): void
    {
        $vendor = VendorMaster::create(['vendor_name' => 'Eventually Fixed Co', 'gst_number' => '', 'contact_phone' => '', 'category' => 'Test']);

        ZohoEntityLink::create([
            'syncable_type' => $vendor->getMorphClass(),
            'syncable_id' => $vendor->id,
            'zoho_module' => 'vendors',
            'failure_count' => 1,
            'last_error' => 'Invalid Element gst_no',
            'last_failed_at' => now(),
        ]);

        Http::fake([
            '*contacts*' => Http::sequence()
                ->push(['contacts' => []], 200)
                ->push(['code' => 0, 'contact' => ['contact_id' => 'zoho-1']], 200),
        ]);

        $result = app(ZohoInventoryService::class)->pushOperationalData(200);
        $this->assertSame(1, $result['vendors']);

        $link = ZohoEntityLink::where('syncable_type', $vendor->getMorphClass())
            ->where('syncable_id', $vendor->id)
            ->where('zoho_module', 'vendors')
            ->first();
        $this->assertSame(0, $link->failure_count);
        $this->assertNull($link->quarantined_at);
    }
}
