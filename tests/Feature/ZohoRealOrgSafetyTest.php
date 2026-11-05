<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\User;
use App\Models\VendorMaster;
use App\Models\ZohoFieldDefinition;
use App\Models\ZohoLocation;
use App\Services\Inventory\InventoryService;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Safety rules for connecting the ERP to the client's real Zoho org, where
// Zoho is the source of truth for items (ZOHO_ITEMS_MASTER=zoho).
class ZohoRealOrgSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.zoho.inventory.sync_enabled', true);
        config()->set('services.zoho.inventory.organization_id', 'org-1');
        config()->set('services.zoho.inventory.refresh_token', 'refresh-1');
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        config()->set('services.zoho.inventory.items_master', 'zoho');
        Cache::put('zoho_inventory_access_token', 'token-1', 3300);

        Http::fake(function (Request $request) {
            $this->sent[] = [$request->method(), $request->url(), $request->data()];
            $url = $request->url();
            $get = $request->method() === 'GET';

            return match (true) {
                str_contains($url, '/contacts') => $get ? Http::response(['contacts' => [['contact_id' => 'v-1', 'contact_name' => 'Real Vendor', 'contact_type' => 'vendor']]]) : Http::response(['code' => 0, 'contact' => ['contact_id' => 'v-1']]),
                str_contains($url, '/purchaseorders') && $get && str_contains($url, 'purchaseorder_number=PO-0099') => Http::response(['purchaseorders' => [['purchaseorder_id' => 'zpo-99', 'purchaseorder_number' => 'PO-0099']]]),
                str_contains($url, '/purchaseorders/zpo-99') => $get ? Http::response(['purchaseorder' => ['purchaseorder_id' => 'zpo-99']]) : Http::response(['code' => 0, 'purchaseorder' => ['purchaseorder_id' => 'zpo-99']]),
                str_contains($url, '/purchaseorders') => $get ? Http::response(['purchaseorders' => []]) : Http::response(['code' => 0, 'purchaseorder' => ['purchaseorder_id' => 'zpo-new']]),
                str_contains($url, '/items') => $get ? Http::response(['items' => []]) : Http::response(['code' => 0, 'item' => ['item_id' => 'created-by-erp']]),
                str_contains($url, '/bundles') => Http::response(['code' => 0, 'bundle' => ['bundle_id' => 'zb-1']]),
                default => Http::response(['code' => 0]),
            };
        });
    }

    private function writes(string $path): array
    {
        return array_values(array_filter($this->sent, fn ($r) => $r[0] !== 'GET' && str_contains($r[1], $path)));
    }

    private function po(string $number, string $product): PurchaseOrder
    {
        VendorMaster::withoutEvents(fn () => VendorMaster::create(['vendor_name' => 'Real Vendor', 'gst_number' => '27AAAAA0000A1Z5', 'contact_phone' => '1', 'category' => 'RM', 'active' => true]));
        $po = PurchaseOrder::withoutEvents(fn () => PurchaseOrder::create(['po_number' => $number, 'vendor_name' => 'Real Vendor', 'po_date' => '2026-10-08', 'valid_upto' => '2026-11-30',
            'zoho_custom_fields' => ['cf_purchase_nature' => 'Inward Stock'], 'status' => 'Created']));
        $po->lines()->getRelated()::withoutEvents(fn () => $po->lines()->create(['product' => $product, 'quantity' => 10, 'list_price' => 5]));

        return $po->load('lines');
    }

    public function test_erp_never_creates_or_updates_items_in_zoho(): void
    {
        $sku = SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => 'Local Only Item', 'category' => 'X', 'unit' => 'PCS']));

        $this->assertFalse(app(ZohoInventoryService::class)->pushItem($sku));
        $this->assertSame([], $this->writes('/items'));
    }

    public function test_po_with_an_item_missing_in_zoho_is_not_sent_and_says_why(): void
    {
        $result = app(ZohoInventoryService::class)->pushPurchaseOrder($this->po('PO-0100', 'Unknown Product'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'Unknown Product' is not in Zoho", $result['message']);
        $this->assertSame([], $this->writes('/purchaseorders'));
        $this->assertSame([], $this->writes('/items'));
    }

    public function test_po_uses_pulled_zoho_item_and_sends_zoho_required_custom_fields(): void
    {
        SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => 'PCM Solution TN-1', 'category' => 'X', 'unit' => 'KG', 'zoho_item_id' => 'zi-20']));
        ZohoFieldDefinition::create(['entity' => 'purchaseorder', 'api_name' => 'cf_purchase_nature', 'label' => 'Purchase Nature', 'data_type' => 'dropdown', 'is_mandatory' => true, 'zoho_field_id' => 'cf-id-2']);

        $result = app(ZohoInventoryService::class)->pushPurchaseOrder($this->po('PO-0101', 'PCM Solution TN-1'));

        $this->assertTrue($result['success']);
        $payload = $this->writes('/purchaseorders')[0][2];
        $this->assertSame('zi-20', $payload['line_items'][0]['item_id']);
        $this->assertContains(['customfield_id' => 'cf-id-2', 'value' => 'Inward Stock'], $payload['custom_fields']);
        $this->assertContains(['api_name' => 'cf_po_valid_upto', 'value' => '2026-11-30'], $payload['custom_fields']);
    }

    public function test_po_pulled_from_zoho_is_updated_not_duplicated(): void
    {
        SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => 'PCM Solution TN-1', 'category' => 'X', 'unit' => 'KG', 'zoho_item_id' => 'zi-20']));

        $result = app(ZohoInventoryService::class)->pushPurchaseOrder($this->po('PO-0099', 'PCM Solution TN-1'));

        $this->assertSame('updated', $result['action']);
        $this->assertSame('PUT', $this->writes('/purchaseorders')[0][0]);
        $this->assertStringContainsString('/purchaseorders/zpo-99', $this->writes('/purchaseorders')[0][1]);
    }

    public function test_pulled_master_data_is_not_pushed_back(): void
    {
        ZohoInventoryService::$pullingFromZoho = true;
        try {
            \App\Models\Tan90\MasterData\Customer::factory()->create();
        } catch (\Throwable) {
            // no factory needed - the point is no outbound write happened
        } finally {
            ZohoInventoryService::$pullingFromZoho = false;
        }

        $this->assertSame([], array_filter($this->sent, fn ($r) => $r[0] !== 'GET'));
    }

    public function test_crm_is_skipped_quietly_when_not_configured(): void
    {
        config()->set('services.zoho.inventory.organization_id', null); // Inventory off -> CRM branch
        config()->set('services.zoho.client_id', null);

        PurchaseOrder::create(['po_number' => 'PO-CRM-1', 'vendor_name' => 'X', 'status' => 'Created']);

        $this->assertSame([], array_filter($this->sent, fn ($r) => str_contains($r[1], 'oauth')));
    }

    private function bundleSetup(): array
    {
        $map = fn ($sku, $type, $zohoId, $opening) => tap(InventoryItem::create(['sku' => $sku, 'item_type' => $type, 'unit' => 'PCS', 'opening_qty' => $opening]),
            fn () => $zohoId && SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => $sku, 'category' => 'X', 'unit' => 'PCS', 'zoho_item_id' => $zohoId])));
        $fg = $map('TN-1 Sachet', 'fg', 'zfg-1', 0);
        $pcm = $map('PCM TN-1', 'rm', 'zrm-1', 100);
        $film = $map('Sachet Film', 'pm', 'zpm-1', 100);
        $fg->bomLines()->create(['component_item_id' => $pcm->id, 'qty_per_unit' => 0.1]);
        $fg->bomLines()->create(['component_item_id' => $film->id, 'qty_per_unit' => 1]);
        ZohoLocation::create(['zoho_location_id' => 'loc-mum', 'name' => 'Tan90 Mumbai (Warehouse)']);

        return [$fg, $pcm, $film];
    }

    public function test_create_bundle_creates_a_zoho_bundle_with_used_plus_wastage(): void
    {
        [$fg, $pcm] = $this->bundleSetup();
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));

        Volt::test('inventory.production')
            ->set('fgItemId', (string) $fg->id)->set('qty', '50')->set('wastage', [$pcm->id => '2'])
            ->call('produce')->assertHasErrors('zohoLocationId')
            ->set('zohoLocationId', 'loc-mum')->call('produce')->assertHasNoErrors();

        $bundle = $this->writes('/bundles')[0][2];
        $this->assertSame(['zfg-1', 50.0, 'loc-mum'], [$bundle['composite_item_id'], (float) $bundle['quantity_to_bundle'], $bundle['location_id']]);
        $this->assertEqualsCanonicalizing([['item_id' => 'zrm-1', 'quantity_consumed' => 7.0], ['item_id' => 'zpm-1', 'quantity_consumed' => 50.0]], $bundle['line_items']);
        $production = \App\Models\Inventory\InventoryProduction::first();
        $this->assertSame(['sent', 'zb-1'], [$production->zoho_status, $production->zoho_bundle_id]);
    }

    public function test_bundle_with_a_non_zoho_component_is_recorded_as_failed_and_erp_stock_still_moves(): void
    {
        [$fg] = $this->bundleSetup();
        $local = InventoryItem::create(['sku' => 'Local Glue', 'item_type' => 'rm', 'unit' => 'PCS', 'opening_qty' => 10]);
        $fg->bomLines()->create(['component_item_id' => $local->id, 'qty_per_unit' => 1]);
        $production = app(InventoryService::class)->produce($fg, 5, [], null, User::factory()->create());
        $production->forceFill(['zoho_location_id' => 'loc-mum'])->save();

        $this->assertSame('failed', app(ZohoInventoryService::class)->pushBundle($production));
        $this->assertStringContainsString("'Local Glue' is not a Zoho item", $production->fresh()->zoho_error);
        $this->assertSame([], $this->writes('/bundles'));
        $this->assertSame(5.0, app(InventoryService::class)->available($fg->fresh()));
    }

    public function test_records_from_before_the_cutover_are_never_pushed(): void
    {
        SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => 'PCM Solution TN-1', 'category' => 'X', 'unit' => 'KG', 'zoho_item_id' => 'zi-20']));
        $old = $this->po('PO-OLD-1', 'PCM Solution TN-1');
        PurchaseOrder::withoutEvents(fn () => $old->forceFill(['created_at' => now()->subDays(3)])->save());
        config()->set('services.zoho.inventory.push_cutover_at', now()->addMinute()->toDateTimeString()); // everything above is "old"

        $this->assertSame('skipped', app(ZohoInventoryService::class)->pushPurchaseOrder($old)['action']);
        $stats = app(ZohoInventoryService::class)->pushOperationalData();

        $this->assertSame([0, 0], [$stats['purchase_orders'], $stats['failed']]);
        $this->assertSame([], array_filter($this->sent, fn ($r) => $r[0] !== 'GET'));
    }

    public function test_read_only_mode_never_writes_anything_to_zoho(): void
    {
        config()->set('services.zoho.inventory.read_only', true);
        SkuMaster::withoutEvents(fn () => SkuMaster::create(['sku' => 'PCM Solution TN-1', 'category' => 'X', 'unit' => 'KG', 'zoho_item_id' => 'zi-20']));
        [$fg] = $this->bundleSetup();
        $zoho = app(ZohoInventoryService::class);

        // Every kind of push, plus the 30-minute job and normal model saves.
        $this->assertSame('skipped', $zoho->pushPurchaseOrder($this->po('PO-RO-1', 'PCM Solution TN-1'))['action']);
        $this->assertTrue($zoho->pushOperationalData()['skipped']);
        $this->assertSame('skipped', $zoho->pushBundle(app(InventoryService::class)->produce($fg, 5, [], null, User::factory()->create())));
        VendorMaster::create(['vendor_name' => 'Saved In ERP', 'gst_number' => '27BBBBB0000B1Z5', 'contact_phone' => '2', 'category' => 'RM', 'active' => true]);
        PurchaseOrder::create(['po_number' => 'PO-RO-2', 'vendor_name' => 'Saved In ERP', 'status' => 'Created']);

        // Even a direct write through the Zoho client is refused before it is sent.
        $method = new \ReflectionMethod($zoho, 'inventoryRequest');
        $response = $method->invoke($zoho)->post('https://www.zohoapis.in/inventory/v1/items?organization_id=org-1', ['name' => 'x']);
        $this->assertSame(403, $response->status());
        $this->assertTrue((bool) $response->json('blocked_read_only'));

        $this->assertSame([], array_filter($this->sent, fn ($r) => $r[0] !== 'GET'));
    }

    public function test_bundle_is_skipped_when_zoho_writing_is_off(): void
    {
        [$fg] = $this->bundleSetup();
        config()->set('services.zoho.inventory.write_enabled', false);
        $production = app(InventoryService::class)->produce($fg, 5, [], null, User::factory()->create());

        $this->assertSame('skipped', app(ZohoInventoryService::class)->pushBundle($production));
        $this->assertSame([], $this->writes('/bundles'));
    }
}
