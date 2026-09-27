<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Space100PercentVerificationTest extends TestCase
{
    public function test_activity_export_returns_csv(): void
    {
        $admin = User::where('email', 'superadmin@tan90.demo')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('access.activity.export'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));
    }

    public function test_hierarchy_page_loads_with_shifts(): void
    {
        $admin = User::where('email', 'superadmin@tan90.demo')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('access.hierarchy.index'));

        $response->assertOk();
        $response->assertSee('Shifts');
        $response->assertSee('Add Shift');
    }

    public function test_dock_scheduling_page_loads(): void
    {
        $storeExec = User::where('role', 'storeExec')->firstOrFail();

        $response = $this->actingAs($storeExec)->get(route('unloading.dock-scheduling'));

        $response->assertOk();
        $response->assertSee('Dock Scheduling');
    }

    public function test_vendor_submissions_page_loads_with_asn_fields(): void
    {
        $vendor = User::where('role', 'vendor')->firstOrFail();

        $this->actingAs($vendor);

        Volt::test('vendor.submissions')
            ->set('adding', true)
            ->assertSee('Vehicle Number')
            ->assertSee('Expected Arrival');
    }
}
