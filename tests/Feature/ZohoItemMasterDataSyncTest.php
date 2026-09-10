<?php

namespace Tests\Feature;

use App\Models\SkuMaster;
use App\Models\Tan90\MasterData\Item as MasterDataItem;
use App\Models\Tan90\MasterData\ItemCategory;
use App\Models\Tan90\MasterData\Uom;
use App\Services\ZohoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Same disconnect as ZohoVendorMasterDataSyncTest, on the item/product side:
 * SkuMaster (what Zoho item sync writes to) and Tan90\MasterData\Item (the
 * Master Data "Products/SKUs" screen) were two entirely separate tables.
 */
class ZohoItemMasterDataSyncTest extends TestCase
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

    private function fakeZoho(array $items): void
    {
        Http::fake([
            '*contacts*' => Http::response(['contacts' => []], 200),
            '*items*' => Http::response(['items' => $items], 200),
        ]);
    }

    public function test_syncing_items_from_zoho_populates_both_sku_master_and_master_data_item(): void
    {
        $this->fakeZoho([[
            'sku' => 'TN-RAW-001',
            'name' => 'Magnesium Nitrate',
            'unit' => 'kg',
            'rate' => 42.5,
            'status' => 'active',
        ]]);

        $result = app(ZohoInventoryService::class)->syncMasterData(200);
        $this->assertSame(1, $result['items']);

        $skuMaster = SkuMaster::where('sku', 'TN-RAW-001')->first();
        $this->assertNotNull($skuMaster);

        $masterDataItem = MasterDataItem::where('sku', 'TN-RAW-001')->first();
        $this->assertNotNull($masterDataItem);
        $this->assertSame('Magnesium Nitrate', $masterDataItem->name);
        $this->assertSame('approved', $masterDataItem->approval_status);
        $this->assertEquals(42.5, (float) $masterDataItem->standard_cost);

        $category = ItemCategory::find($masterDataItem->tan90_item_category_id);
        $this->assertSame('ZOHO-ITEM', $category->code);

        $uom = Uom::find($masterDataItem->tan90_uom_id);
        $this->assertSame('KG', $uom->code);
    }

    public function test_re_syncing_the_same_item_updates_rather_than_duplicates(): void
    {
        $this->fakeZoho([[
            'sku' => 'TN-RAW-002', 'name' => 'Potassium Chloride', 'unit' => 'kg', 'status' => 'active',
        ]]);

        $service = app(ZohoInventoryService::class);
        $service->syncMasterData(200);
        $service->syncMasterData(200);

        $this->assertSame(1, MasterDataItem::where('sku', 'TN-RAW-002')->count());
        // The UOM must also not be duplicated across repeated syncs.
        $this->assertSame(1, Uom::where('code', 'KG')->count());
    }

    public function test_items_with_different_units_get_distinct_uoms(): void
    {
        $this->fakeZoho([
            ['sku' => 'TN-A', 'name' => 'Item A', 'unit' => 'kg', 'status' => 'active'],
            ['sku' => 'TN-B', 'name' => 'Item B', 'unit' => 'pcs', 'status' => 'active'],
        ]);

        app(ZohoInventoryService::class)->syncMasterData(200);

        $this->assertNotNull(Uom::where('code', 'KG')->first());
        $this->assertNotNull(Uom::where('code', 'PCS')->first());
    }
}
