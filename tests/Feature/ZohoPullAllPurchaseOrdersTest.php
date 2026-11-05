<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// zoho:pull-all-pos brings only the Zoho POs missing in the ERP, read-only,
// with the real Zoho status, and stops at --max.
class ZohoPullAllPurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulls_only_missing_pos_with_their_status_and_respects_max(): void
    {
        config(['services.zoho_items_pull' => [
            'organization_id' => '1', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.test', 'api_base_url' => 'https://zoho.test/inventory/v1', 'schedule_enabled' => false,
        ]]);
        $existing = PurchaseOrder::factory()->create(['po_number' => 'PO-OLD-1', 'status' => 'Approved', 'vendor_name' => 'Keep Me']);
        $detail = fn ($n, $status) => ['purchaseorder' => [
            'purchaseorder_number' => $n, 'status' => $status, 'vendor_name' => 'Zoho Vendor '.$n, 'date' => '2026-10-01',
            'terms' => 'T&C '.$n, 'line_items' => [['name' => 'Panel', 'quantity' => 4, 'rate' => 10]],
        ]];
        Http::fake([
            'accounts.test/*' => Http::response(['access_token' => 'tok']),
            'zoho.test/inventory/v1/purchaseorders/1*' => Http::response($detail('PO-Z-1', 'received')),
            'zoho.test/inventory/v1/purchaseorders/2*' => Http::response($detail('PO-Z-2', 'issued')),
            'zoho.test/inventory/v1/purchaseorders/3*' => Http::response($detail('PO-Z-3', 'cancelled')),
            'zoho.test/inventory/v1/purchaseorders*' => Http::response(['purchaseorders' => [
                ['purchaseorder_id' => '1', 'purchaseorder_number' => 'PO-Z-1', 'status' => 'received'],
                ['purchaseorder_id' => '9', 'purchaseorder_number' => 'po-old-1', 'status' => 'issued'],
                ['purchaseorder_id' => '2', 'purchaseorder_number' => 'PO-Z-2', 'status' => 'issued'],
                ['purchaseorder_id' => '3', 'purchaseorder_number' => 'PO-Z-3', 'status' => 'cancelled'],
            ], 'page_context' => ['has_more_page' => false]]),
            '*' => Http::response([], 500),
        ]);

        $this->artisan('zoho:pull-all-pos', ['--max' => 2])->assertSuccessful();
        $this->assertSame(['Delivered', 'Approved'], [PurchaseOrder::where('po_number', 'PO-Z-1')->value('status'), PurchaseOrder::where('po_number', 'PO-Z-2')->value('status')]);
        $this->assertFalse(PurchaseOrder::where('po_number', 'PO-Z-3')->exists()); // over --max: next run
        $this->assertSame('T&C PO-Z-1', PurchaseOrder::where('po_number', 'PO-Z-1')->value('terms_and_conditions'));
        $this->assertSame(1, PurchaseOrder::where('po_number', 'PO-Z-1')->first()->lines()->count());
        $this->assertSame('Keep Me', $existing->fresh()->vendor_name); // already in the ERP: untouched

        $this->artisan('zoho:pull-all-pos')->assertSuccessful();
        $this->assertSame('Cancelled', PurchaseOrder::where('po_number', 'PO-Z-3')->value('status'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_contains($request->url(), 'accounts.test'));
    }

    public function test_zoho_status_mapping(): void
    {
        foreach (['draft' => 'Created', 'pending_approval' => 'Created', 'approved' => 'Approved', 'issued' => 'Approved', 'accepted' => 'Approved',
            'partially_received' => 'Approved', 'received' => 'Delivered', 'billed' => 'Delivered', 'closed' => 'Delivered', 'cancelled' => 'Cancelled'] as $zoho => $erp) {
            $this->assertSame($erp, ZohoInventoryService::zohoPoStatus(['status' => $zoho]), $zoho);
        }
    }
}
