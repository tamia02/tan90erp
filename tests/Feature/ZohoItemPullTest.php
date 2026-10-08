<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\SkuMaster;
use App\Models\User;
use App\Services\Zoho\ZohoItemPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ZohoItemPullTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zoho_items_pull' => [
            'organization_id' => '600', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1', 'schedule_enabled' => false,
        ]]);
    }

    private array $pages = [[], []];

    private bool $faked = false;

    // One fake per test; later calls just swap the pages it serves (a second
    // Http::fake() would be shadowed by the first one's matching stub).
    private function fakeZoho(array $page1, array $page2 = []): void
    {
        $this->pages = [$page1, $page2];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'accounts.zoho.test/*' => Http::response(['access_token' => 'tok']),
            'zoho.test/inventory/v1/items*' => function (Request $request) {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response([
                    'items' => $this->pages[$page === 1 ? 0 : 1],
                    'page_context' => ['has_more_page' => $page === 1 && $this->pages[1] !== []],
                ]);
            },
        ]);
    }

    private function item(string $id, string $name, array $extra = []): array
    {
        return $extra + ['item_id' => $id, 'name' => $name, 'sku' => 'SKU-'.$id, 'unit' => 'pcs', 'status' => 'active',
            'item_type' => 'inventory', 'is_combo_product' => false, 'rate' => 10, 'tags' => []];
    }

    public function test_pulls_every_page_classifies_and_never_writes_to_zoho(): void
    {
        $this->fakeZoho(
            [$this->item('1', 'Tan90 TN-1 Thermal Sachet', ['is_combo_product' => true]),
                $this->item('2', 'PCM Solution TN-1', ['tags' => [['tag_option_name' => 'PCM']], 'unit' => 'Kgs'])],
            [$this->item('3', 'Empty Panel 1.2ltr', ['tags' => [['tag_option_name' => 'Panel']]]),
                $this->item('4', 'Gel Pack', ['item_type' => 'sales_and_purchases', 'status' => 'inactive'])],
        );

        $stats = app(ZohoItemPullService::class)->pull();

        $this->assertSame(4, $stats['fetched']);
        $this->assertSame(['fg', 'rm', 'pm', 'trading'], [
            InventoryItem::where('sku', 'Tan90 TN-1 Thermal Sachet')->value('item_type'),
            InventoryItem::where('sku', 'PCM Solution TN-1')->value('item_type'),
            InventoryItem::where('sku', 'Empty Panel 1.2ltr')->value('item_type'),
            InventoryItem::where('sku', 'Gel Pack')->value('item_type'),
        ]);
        $pcm = SkuMaster::where('zoho_item_id', '2')->firstOrFail();
        $this->assertSame(['PCM Solution TN-1', 'SKU-2', 'KG', true], [$pcm->sku, $pcm->product_code, $pcm->unit, $pcm->mapped]);
        $this->assertFalse(SkuMaster::where('zoho_item_id', '4')->value('active'));
        $this->assertFalse(InventoryItem::where('sku', 'Gel Pack')->value('active'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_contains($r->url(), 'oauth'));
    }

    public function test_second_pull_updates_instead_of_duplicating_and_follows_renames(): void
    {
        $this->fakeZoho([$this->item('9', 'Old Name')]);
        app(ZohoItemPullService::class)->pull();

        $this->fakeZoho([$this->item('9', 'New Name', ['rate' => 25])]);
        $stats = app(ZohoItemPullService::class)->pull();

        $this->assertSame([0, 1], [$stats['created'], $stats['updated']]);
        $this->assertSame(1, SkuMaster::where('zoho_item_id', '9')->count());
        $this->assertSame('New Name', SkuMaster::where('zoho_item_id', '9')->value('sku'));
        $this->assertSame('New Name', InventoryItem::where('sku', 'New Name')->value('sku'));
        $this->assertNull(InventoryItem::where('sku', 'Old Name')->first());
    }

    public function test_duplicate_and_non_breaking_space_names_are_kept_apart(): void
    {
        $this->fakeZoho([$this->item('5', 'Cold Storage Box'), $this->item('6', "Cold Storage\u{00A0}Box")]);

        $stats = app(ZohoItemPullService::class)->pull();

        $this->assertSame(2, $stats['duplicate_names']);
        $this->assertEqualsCanonicalizing(['Cold Storage Box [SKU-5]', 'Cold Storage Box [SKU-6]'], SkuMaster::pluck('sku')->all());
    }

    public function test_existing_erp_item_with_same_name_is_linked_not_duplicated(): void
    {
        SkuMaster::create(['sku' => 'PCM Solution TN-1', 'category' => 'PCM — Raw Material', 'unit' => 'KG', 'mapped' => true]);
        $this->fakeZoho([$this->item('2', 'PCM Solution TN-1')]);

        app(ZohoItemPullService::class)->pull();

        $this->assertSame(1, SkuMaster::count());
        $this->assertSame('2', SkuMaster::first()->zoho_item_id);
    }

    public function test_zoho_items_cannot_be_deleted_in_erp(): void
    {
        $this->fakeZoho([$this->item('7', 'Zoho Owned Item')]);
        app(ZohoItemPullService::class)->pull();
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));

        Volt::test('admin.sku-master')->call('deleteSku', SkuMaster::where('zoho_item_id', '7')->value('id'))->assertForbidden();
        $this->assertSame(1, SkuMaster::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeZoho([$this->item('1', 'A'), $this->item('2', 'B')]);

        $this->artisan('zoho:pull-items', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, SkuMaster::count());
    }
}
