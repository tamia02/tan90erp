<?php

namespace Tests\Feature;

use App\Models\Inventory\InventoryItem;
use App\Models\SkuMaster;
use App\Services\Zoho\ZohoItemPullService;
use App\Services\Zoho\ZohoRecipePullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoRecipePullTest extends TestCase
{
    use RefreshDatabase;

    private array $composites = [];

    private array $details = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.zoho_items_pull' => [
            'organization_id' => '600', 'refresh_token' => 'r', 'client_id' => 'c', 'client_secret' => 's',
            'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1', 'schedule_enabled' => false,
        ]]);

        $item = fn ($id, $name, $combo = false, $tags = []) => ['item_id' => $id, 'name' => $name, 'sku' => 'S'.$id, 'unit' => 'pcs',
            'status' => 'active', 'item_type' => 'inventory', 'is_combo_product' => $combo, 'rate' => 1, 'tags' => $tags];
        $items = [
            $item('10', 'TN-1 Sachet 100gm', true),
            $item('20', 'PCM Solution TN-1', false, [['tag_option_name' => 'PCM']]),
            $item('30', 'Sachet Film', false, [['tag_option_name' => 'Sachet']]),
        ];
        $this->composites = [['composite_item_id' => '10', 'name' => 'TN-1 Sachet 100gm', 'last_modified_time' => '2026-10-01T10:00:00+0530']];
        $this->details = ['10' => ['composite_item' => ['composite_component_items' => [
            ['item_id' => '20', 'quantity' => 0.1], ['item_id' => '30', 'quantity' => 1], ['item_id' => '999', 'quantity' => 2],
        ]]]];

        Http::fake([
            'accounts.zoho.test/*' => Http::response(['access_token' => 'tok']),
            'zoho.test/inventory/v1/items*' => Http::response(['items' => $items, 'page_context' => ['has_more_page' => false]]),
            'zoho.test/inventory/v1/compositeitems/*' => fn (Request $r) => Http::response($this->details[basename(parse_url($r->url(), PHP_URL_PATH))] ?? []),
            'zoho.test/inventory/v1/compositeitems*' => fn () => Http::response(['composite_items' => $this->composites, 'page_context' => ['has_more_page' => false]]),
        ]);
        app(ZohoItemPullService::class)->pull();
    }

    private function recipe(): array
    {
        $fg = InventoryItem::where('sku', 'TN-1 Sachet 100gm')->firstOrFail();

        return $fg->bomLines()->with('component')->get()
            ->mapWithKeys(fn ($l) => [$l->component->sku => (float) $l->qty_per_unit])->sort()->all();
    }

    public function test_copies_zoho_recipe_onto_the_finished_good_get_only(): void
    {
        $stats = app(ZohoRecipePullService::class)->pull(pauseMs: 0);

        $this->assertSame([1, 2, 1], [$stats['recipes_updated'], $stats['lines_written'], $stats['missing_components']]);
        $this->assertSame(['PCM Solution TN-1' => 0.1, 'Sachet Film' => 1.0], $this->recipe());
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_contains($r->url(), 'oauth'));
    }

    public function test_unchanged_recipes_are_not_fetched_again_and_changes_replace_old_lines(): void
    {
        app(ZohoRecipePullService::class)->pull(pauseMs: 0);

        $again = app(ZohoRecipePullService::class)->pull(pauseMs: 0);
        $this->assertSame([0, 1], [$again['recipes_updated'], $again['unchanged']]);

        $this->composites[0]['last_modified_time'] = '2026-10-05T10:00:00+0530';
        $this->details['10']['composite_item']['composite_component_items'] = [['item_id' => '20', 'quantity' => 0.25]];
        app(ZohoRecipePullService::class)->pull(pauseMs: 0);

        $this->assertSame(['PCM Solution TN-1' => 0.25], $this->recipe());
        $this->assertSame('2026-10-05T10:00:00+0530', SkuMaster::where('zoho_item_id', '10')->value('zoho_recipe_modified_at'));
    }

    public function test_dry_run_fetches_no_details_and_writes_nothing(): void
    {
        $stats = app(ZohoRecipePullService::class)->pull(dryRun: true, pauseMs: 0);

        $this->assertSame(1, $stats['would_fetch']);
        $this->assertSame([], $this->recipe());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'compositeitems/10'));
    }
}
