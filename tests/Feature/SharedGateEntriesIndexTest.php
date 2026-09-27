<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Client-reported gap, reversing an earlier explicit requirement: the
 * original build deliberately kept every role's sidebar to its own module
 * only (see RoleNavigation's own comment: "nothing shared across roles per
 * the client"). A Store Manager's own screens (GRN dashboard/check/register)
 * only ever showed entries already at the qc_done/closed stage — an entry
 * Guard just logged, or one stuck at pending_validation with no ValidationIssue
 * raised yet, was invisible anywhere in Store Manager's or Store Executive's
 * UI. This is the fix: a shared, full "All Gate Entries" list every
 * internal-staff role can now browse, regardless of the entry's stage.
 */
class SharedGateEntriesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_store_manager_sees_a_brand_new_guard_entry_that_no_grn_screen_would_show(): void
    {
        $gate = GateEntry::factory()->create(['gate_no' => 'GATE-INDEX-1', 'status' => 'pending_validation']);

        $storeManager = User::factory()->create(['role' => 'storeManager']);
        $this->actingAs($storeManager);

        Volt::test('shared.gate-entries-index')->assertSee('GATE-INDEX-1');
    }

    public function test_search_filters_the_list(): void
    {
        GateEntry::factory()->create(['gate_no' => 'GATE-INDEX-2', 'vendor_name' => 'Findable Vendor Co']);
        GateEntry::factory()->create(['gate_no' => 'GATE-INDEX-3', 'vendor_name' => 'Unrelated Vendor Co']);

        $storeExec = User::factory()->create(['role' => 'storeExec']);
        $this->actingAs($storeExec);

        Volt::test('shared.gate-entries-index')
            ->set('search', 'Findable')
            ->assertSee('GATE-INDEX-2')
            ->assertDontSee('GATE-INDEX-3');
    }

    public function test_a_vendor_only_sees_their_own_entries(): void
    {
        GateEntry::factory()->create(['gate_no' => 'GATE-INDEX-4', 'vendor_name' => 'My Own Company']);
        GateEntry::factory()->create(['gate_no' => 'GATE-INDEX-5', 'vendor_name' => 'Someone Elses Company']);

        $vendor = User::factory()->create(['name' => 'My Own Company', 'role' => 'vendor']);
        $this->actingAs($vendor);

        Volt::test('shared.gate-entries-index')
            ->assertSee('GATE-INDEX-4')
            ->assertDontSee('GATE-INDEX-5');
    }
}
