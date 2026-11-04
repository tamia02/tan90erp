<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Outward\OutwardShipment;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\Tan90\MasterData\Location;
use App\Models\User;
use App\Services\PoNumberService;
use App\Services\Tan90\MasterData\NumberSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-12 location-based access (part A): a person tied to a site sees only
// that site's gate entries / GRN / QC / POs / shipments, plus rows with no site.
// Super Admin and people with no site see everything, as before.
class SiteLocationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Location $bhiwandi;

    private Location $chennai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bhiwandi = Location::create(['code' => 'LOC-BHW', 'name' => 'Bhiwandi', 'city' => 'Bhiwandi', 'state' => 'Maharashtra', 'status' => 'active', 'approval_status' => 'approved']);
        $this->chennai = Location::create(['code' => 'LOC-CHN', 'name' => 'Chennai', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'status' => 'active', 'approval_status' => 'approved']);
    }

    private function user(Role $role, ?Location $site = null): User
    {
        return User::factory()->create(['role' => $role, 'location_id' => $site?->id])->fresh();
    }

    public function test_records_get_their_site_from_what_they_already_store(): void
    {
        $this->assertSame($this->bhiwandi->id, GateEntry::factory()->create(['location' => 'Bhiwandi WH (MU)'])->location_id);
        $this->assertSame($this->chennai->id, GateEntry::factory()->create(['location' => 'CHENNAI (MU)'])->location_id);
        $this->assertSame($this->chennai->id, PurchaseOrder::factory()->create(['warehouse_location' => 'Chennai'])->location_id);
        $this->assertNull(PurchaseOrder::factory()->create()->location_id, 'Zoho-style PO without a warehouse has no site');
    }

    public function test_site_staff_see_only_their_site_and_rows_without_a_site(): void
    {
        $mumbaiGate = GateEntry::factory()->create(['location' => 'Bhiwandi WH (MU)', 'gate_no' => 'GP-IN-2026-000101']);
        $chennaiGate = GateEntry::factory()->create(['location' => 'CHENNAI (MU)', 'gate_no' => 'GP-IN-2026-000102']);
        QcResult::create(['gate_entry_id' => $mumbaiGate->id, 'sku' => 'RM', 'po_qty' => 5, 'invoice_qty' => 5, 'physical_received' => 5, 'accepted_qty' => 5]);
        QcResult::create(['gate_entry_id' => $chennaiGate->id, 'sku' => 'RM', 'po_qty' => 7, 'invoice_qty' => 7, 'physical_received' => 7, 'accepted_qty' => 7]);
        $zohoPo = PurchaseOrder::factory()->create(['po_number' => 'POTG26279001']);
        $chennaiPo = PurchaseOrder::factory()->create(['po_number' => 'POTG26279002', 'warehouse_location' => 'Chennai']);

        $this->actingAs($this->user(Role::StoreManager, $this->chennai));
        $this->assertSame([$chennaiGate->id], GateEntry::pluck('id')->all());
        $this->assertSame([$chennaiGate->id], QcResult::pluck('gate_entry_id')->all());
        $this->assertEqualsCanonicalizing([$zohoPo->id, $chennaiPo->id], PurchaseOrder::pluck('id')->all());
        $this->get(route('gate-entries.index'))->assertOk()->assertSee('GP-IN-2026-000102')->assertDontSee('GP-IN-2026-000101');
        $this->get(route('gate-entries.show', $mumbaiGate))->assertNotFound();

        $this->actingAs($this->user(Role::StoreManager, $this->bhiwandi));
        $this->assertSame([$mumbaiGate->id], GateEntry::pluck('id')->all());
        $this->assertSame([$zohoPo->id], PurchaseOrder::pluck('id')->all(), 'old POs without a site stay visible');

        // No site and Super Admin: everything, as before.
        $this->actingAs($this->user(Role::StoreManager));
        $this->assertSame(2, GateEntry::count());
        $this->actingAs($this->user(Role::Admin, $this->chennai));
        $this->assertSame(2, GateEntry::count());
    }

    public function test_new_po_takes_the_creators_site_and_numbers_stay_unique_across_sites(): void
    {
        $fy = NumberSeriesService::financialYear();
        $this->actingAs($this->user(Role::StoreManager, $this->chennai));
        $po = PurchaseOrder::factory()->create(['po_number' => "POTG{$fy}0001"]);
        $this->assertSame($this->chennai->id, $po->location_id);

        $this->actingAs($this->user(Role::StoreManager, $this->bhiwandi));
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame("POTG{$fy}0002", app(PoNumberService::class)->next('Trading'), 'Chennai already used 0001');
    }

    public function test_guard_of_a_site_can_only_pick_their_gate(): void
    {
        $this->actingAs($this->user(Role::Guard, $this->chennai));
        Volt::test('guard.bill-scan')->assertSet('location', 'chennai')
            ->set('location', 'bhiwandi')->assertSet('location', 'chennai')
            ->assertViewHas('locationDetails', fn ($l) => array_keys($l) === ['chennai']);

        $this->actingAs($this->user(Role::Guard));
        Volt::test('guard.bill-scan')->assertSet('location', 'bhiwandi')
            ->assertViewHas('locationDetails', fn ($l) => count($l) === 3);
    }

    public function test_outward_ships_only_from_own_site(): void
    {
        $this->actingAs($this->user(Role::StoreManager, $this->chennai));
        Volt::test('inventory.dispatch')->assertViewHas('fromLocations', fn ($l) => $l->pluck('id')->all() === [$this->chennai->id])
            ->set('fromLocationId', (string) $this->bhiwandi->id)->set('toName', 'Someone')->call('createShipment')
            ->assertHasErrors('fromLocationId');
    }

    public function test_super_admin_sets_a_persons_site_in_people(): void
    {
        $this->seed(\Database\Seeders\Access\AccessControlSeeder::class);
        $admin = User::factory()->create(['role' => Role::Admin, 'super_admin' => true]);
        app(\App\Services\Access\SuperAdminAccessSync::class)->syncAll();
        $admin = $admin->fresh();
        $person = $this->user(Role::StoreManager);

        $this->actingAs($admin)->post(route('access.people.job-roles', $person), ['main_role' => 'storeManager', 'location_id' => $this->chennai->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->chennai->id, $person->fresh()->location_id);
        $this->actingAs($admin)->get(route('access.people.show', $person))->assertOk()->assertSee('Location (site)');
    }
}
