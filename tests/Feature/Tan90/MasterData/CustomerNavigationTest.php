<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\Customer;
use App\Support\Tan90ModuleNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

/**
 * Reproduces the reported issue: the "customers" entity was fully built
 * (config, controller, routes, view) and syncing correctly from Zoho, but
 * was reachable only by typing the URL directly — it was missing from both
 * the sidebar nav (Tan90ModuleNavigation::forMasterData()) and the Master
 * Data Control Center's dashboard cards, so nobody could find it.
 */
class CustomerNavigationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_customers_appears_in_the_master_data_sidebar_nav(): void
    {
        $labels = collect(Tan90ModuleNavigation::forMasterData())->pluck('label');

        $this->assertTrue($labels->contains('Customers'));
    }

    public function test_customers_card_and_kpi_appear_on_the_dashboard(): void
    {
        $user = $this->masterDataManager();
        Customer::create([
            'code' => 'CU-TEST-1', 'name' => 'Bharat Serums and Vaccines Limited',
            'segment' => 'Other', 'status' => 'active', 'approval_status' => 'approved',
        ]);

        $this->actingAs($user)->get(route('tan90.master-data.dashboard'))
            ->assertOk()
            ->assertSee('Customer Master')
            ->assertSee('Active Customers');
    }

    public function test_customers_index_page_lists_synced_records(): void
    {
        $user = $this->masterDataManager();
        Customer::create([
            'code' => 'CU-TEST-2', 'name' => 'TATA 1MG Technologies Private Limited',
            'segment' => 'Other', 'status' => 'active', 'approval_status' => 'approved',
        ]);

        $this->actingAs($user)->get(route('tan90.master-data.index', 'customers'))
            ->assertOk()
            ->assertSee('TATA 1MG Technologies Private Limited');
    }
}
