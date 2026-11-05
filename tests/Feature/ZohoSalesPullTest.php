<?php

namespace Tests\Feature;

use App\Models\ZohoSalesDocument;
use App\Services\Zoho\ZohoSalesPullService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoSalesPullTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_04_000001_create_zoho_sales_documents_table.php'))->up();
        config(['services.zoho.inventory.sync_enabled' => true, 'services.zoho.inventory.rate_limit.burst' => 100, 'services.zoho.inventory.rate_limit.per_minute' => 0]);
        config(['services.zoho_items_pull' => [
            'organization_id' => '600', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1',
        ]]);
        Http::preventStrayRequests();
        Http::fake([
            'accounts.zoho.test/*' => Http::response(['access_token' => 'tok']),
            'zoho.test/inventory/v1/*' => function ($request) {
                $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
                $type = ['salesorders' => 'salesorder', 'packages' => 'package', 'invoices' => 'invoice'][$endpoint];
                $page = $request['page'];

                return Http::response([$endpoint => [[
                    $type.'_id' => $type.$page, $type.'_number' => 'NUM-'.$page,
                    'salesorder_id' => $type === 'salesorder' ? $type.$page : 'salesorder1', 'salesorder_number' => 'SO-1',
                    'customer_name' => 'Customer', 'date' => '2026-11-04', 'status' => 'draft',
                ]], 'page_context' => ['has_more_page' => $page === 1]]);
            },
        ]);
    }

    public function test_resumes_pages_and_upserts_without_duplicates_or_zoho_writes(): void
    {
        $service = app(ZohoSalesPullService::class);
        $service->pull(1);
        $this->assertSame(3, ZohoSalesDocument::count());
        $this->assertSame(2, Cache::get('zoho:sales-pull:600:packages:page'));
        $service->pull(1);
        $this->assertSame(6, ZohoSalesDocument::count());
        $service->pull(2);
        $this->assertSame(6, ZohoSalesDocument::count());
        $this->assertDatabaseHas('zoho_sales_documents', ['type' => 'package', 'salesorder_number' => 'SO-1']);
        $this->assertSame('NUM-1', ZohoSalesDocument::where('zoho_id', 'package1')->firstOrFail()->payload['package_number']);
        Http::assertNotSent(fn ($request) => ! in_array($request->method(), ['GET']) && ! str_contains($request->url(), '/oauth/v2/token'));
    }

    public function test_dry_run_does_not_persist_records_or_checkpoints(): void
    {
        app(ZohoSalesPullService::class)->pull(1, true);
        $this->assertSame(0, ZohoSalesDocument::count());
        $this->assertNull(Cache::get('zoho:sales-pull:600:packages:page'));
    }

    public function test_can_resume_only_one_module_without_reimporting_completed_modules(): void
    {
        $stats = app(ZohoSalesPullService::class)->pull(1, false, 'invoices');
        $this->assertSame(['invoices'], array_keys($stats));
        $this->assertDatabaseCount('zoho_sales_documents', 1);
        $this->assertDatabaseHas('zoho_sales_documents', ['type' => 'invoice']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/salesorders') || str_contains($request->url(), '/packages'));
    }
}
