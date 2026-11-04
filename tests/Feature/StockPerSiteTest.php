<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Inventory\InventoryBomLine;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\LedgerEntry;
use App\Models\Tan90\MasterData\Location;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Outward\OutwardShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-12 location-based access (part B): stock per site. Site staff see and
// move their own site's stock; all-site users see the total and choose a site.
class StockPerSiteTest extends TestCase
{
    use RefreshDatabase;

    private Location $bhiwandi;

    private Location $chennai;

    private InventoryItem $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bhiwandi = Location::create(['code' => 'LOC-BHW', 'name' => 'Bhiwandi', 'city' => 'Bhiwandi', 'state' => 'Maharashtra', 'status' => 'active', 'approval_status' => 'approved']);
        $this->chennai = Location::create(['code' => 'LOC-CHN', 'name' => 'Chennai', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'status' => 'active', 'approval_status' => 'approved']);
        $this->rm = InventoryItem::create(['sku' => 'Site RM', 'item_type' => 'rm', 'unit' => 'KG', 'opening_qty' => 0]);
        // 100 received through a Chennai gate, 40 through a Bhiwandi gate.
        foreach ([['CHENNAI (MU)', 100], ['Bhiwandi WH (MU)', 40]] as [$gate, $qty]) {
            $entry = GateEntry::factory()->create(['location' => $gate]);
            LedgerEntry::create(['gate_entry_id' => $entry->id, 'sku' => 'Site RM', 'bin' => 'A1', 'bucket' => 'available', 'qty' => $qty]);
        }
    }

    private function user(Role $role, ?Location $site = null): User
    {
        return User::factory()->create(['role' => $role, 'location_id' => $site?->id])->fresh();
    }

    private function final(): float
    {
        return app(InventoryService::class)->available($this->rm);
    }

    public function test_each_site_sees_its_own_stock_and_all_site_users_the_total(): void
    {
        $this->actingAs($this->user(Role::StoreManager, $this->chennai));
        $this->assertSame(100.0, $this->final());
        $this->actingAs($this->user(Role::StoreManager, $this->bhiwandi));
        $this->assertSame(40.0, $this->final());
        $this->actingAs($this->user(Role::StoreManager));
        $this->assertSame(140.0, $this->final(), 'no site = all sites added up, as before');
        $this->assertSame(100.0, app(InventoryService::class)->atSite($this->chennai->id)->available($this->rm));
    }

    public function test_changes_are_booked_to_the_site_and_checked_against_its_stock(): void
    {
        $this->actingAs($user = $this->user(Role::StoreManager, $this->bhiwandi));
        try {
            app(InventoryService::class)->adjust($this->rm, -50, 'count', $user);
            $this->fail('Bhiwandi has only 40 - Chennai stock must not be usable here.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 40', collect($e->errors())->flatten()->implode(' '));
        }
        app(InventoryService::class)->adjust($this->rm, 10, 'count', $user);
        $this->assertSame($this->bhiwandi->id, InventoryMovement::latest('id')->value('location_id'));
        $this->assertSame(50.0, $this->final());

        // All-site user picks Chennai on the Items screen: the change lands there.
        $this->actingAs($this->user(Role::StoreManager));
        Volt::test('inventory.items')->set('stockSite', (string) $this->chennai->id)->assertSee('Stock shown for this site');
        app(InventoryService::class)->atSite($this->chennai->id)->adjust($this->rm, 5, 'count', auth()->user());
        $this->assertSame(105.0, app(InventoryService::class)->atSite($this->chennai->id)->available($this->rm));
        $this->assertSame(155.0, $this->final());
    }

    public function test_create_bundle_uses_the_sites_components(): void
    {
        $fg = InventoryItem::create(['sku' => 'Site FG', 'item_type' => 'fg', 'unit' => 'PCS', 'opening_qty' => 0]);
        InventoryBomLine::create(['fg_item_id' => $fg->id, 'component_item_id' => $this->rm->id, 'qty_per_unit' => 1]);

        $this->actingAs($user = $this->user(Role::StoreManager, $this->bhiwandi));
        try {
            app(InventoryService::class)->produce($fg, 60, [], null, $user);
            $this->fail('Bhiwandi has only 40 RM.');
        } catch (ValidationException) {
        }
        $production = app(InventoryService::class)->produce($fg, 30, [], null, $user);
        $this->assertSame($this->bhiwandi->id, $production->location_id);
        $this->assertSame(10.0, $this->final());
        $this->assertSame(30.0, app(InventoryService::class)->available($fg));

        // Create Bundle screen for an all-site user starts on the main site.
        $this->actingAs($this->user(Role::StoreManager));
        Volt::test('inventory.production')->assertSet('stockSite', (string) $this->bhiwandi->id);
    }

    public function test_outward_uses_the_from_sites_stock(): void
    {
        $this->actingAs($this->user(Role::StoreManager));
        $service = app(OutwardShipmentService::class);
        $this->assertSame(40.0, $service->stockPicture([$this->rm->id], null, $this->bhiwandi->id)[$this->rm->id]['free']);
        $this->assertSame(100.0, $service->stockPicture([$this->rm->id], null, $this->chennai->id)[$this->rm->id]['free']);
    }
}
