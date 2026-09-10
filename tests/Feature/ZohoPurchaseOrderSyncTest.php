<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reproduces the reported bug: syncPurchaseOrderData() keyed po_number off
 * Zoho's `reference_number` — an optional free-text field that's usually
 * blank on a Zoho-native purchase order. Confirmed live against the real
 * Zoho org: "1 synced, 0 skipped, 1 failed" — every PO without a
 * reference_number filled in failed to sync at all. `purchaseorder_number`
 * is Zoho's actual PO identifier and should have been used instead.
 */
class ZohoPurchaseOrderSyncTest extends TestCase
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

    public function test_a_purchase_order_with_no_reference_number_still_syncs(): void
    {
        Http::fake([
            '*purchaseorders?*' => Http::response([
                'purchaseorders' => [[
                    'purchaseorder_id' => 'zoho-po-1',
                    'last_modified_time' => '2026-09-10T10:00:00+0530',
                ]],
            ], 200),
            '*purchaseorders/zoho-po-1*' => Http::response([
                'purchaseorder' => [
                    'purchaseorder_id' => 'zoho-po-1',
                    'purchaseorder_number' => 'PO-000042',
                    'reference_number' => '',
                    'vendor_name' => 'Thermocore Materials Pvt Ltd',
                    'date' => '2026-09-01',
                    'delivery_date' => '2026-09-08',
                    'line_items' => [['name' => 'PCM Raw Compound', 'quantity' => 100, 'rate' => 42]],
                ],
            ], 200),
        ]);

        $result = app(ZohoInventoryService::class)->syncRecentlyModifiedPurchaseOrders();

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['failed']);

        $po = PurchaseOrder::where('po_number', 'PO-000042')->first();
        $this->assertNotNull($po);
        $this->assertSame('Thermocore Materials Pvt Ltd', $po->vendor_name);
        $this->assertSame(1, $po->lines()->count());
    }

    public function test_a_reference_number_when_present_is_stored_as_requisition_number_not_po_number(): void
    {
        Http::fake([
            '*purchaseorders?*' => Http::response([
                'purchaseorders' => [[
                    'purchaseorder_id' => 'zoho-po-2',
                    'last_modified_time' => '2026-09-10T10:00:00+0530',
                ]],
            ], 200),
            '*purchaseorders/zoho-po-2*' => Http::response([
                'purchaseorder' => [
                    'purchaseorder_id' => 'zoho-po-2',
                    'purchaseorder_number' => 'PO-000043',
                    'reference_number' => 'CLIENT-REF-99',
                    'vendor_name' => 'Konkan Insulation Systems',
                ],
            ], 200),
        ]);

        app(ZohoInventoryService::class)->syncRecentlyModifiedPurchaseOrders();

        $po = PurchaseOrder::where('po_number', 'PO-000043')->first();
        $this->assertNotNull($po);
        $this->assertSame('CLIENT-REF-99', $po->requisition_number);
    }
}
