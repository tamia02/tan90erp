<?php

namespace Tests\Feature\AccessControl;

use App\Enums\Role;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Super Admin creates an "Inward Executive" access role, ticks "View GRN
// register" + "View QC queue" and gives it to a Store Executive. Found by
// testing 2026-10-09: the page gate only checked the FIRST permission linked
// to a page (GRN Register has four), and the menu never listed such pages.
class CustomRoleOpensPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_ticked_page_permission_opens_the_page_and_lists_it_in_the_menu(): void
    {
        $this->seed(AccessControlSeeder::class);
        $role = AccessRole::create(['name' => 'Inward Executive', 'label' => 'Inward Executive', 'code' => 'ACCESS-INWARD-EXEC', 'level' => 4, 'status' => 'active']);
        foreach (['grn.register.view', 'qc.queue.view'] as $key) {
            $role->permissions()->attach(AccessPermission::where('key', $key)->value('id'), [
                'allowed' => true, 'effect' => 'allow', 'delegable' => false, 'scope_type' => 'all', 'max_scope_type' => 'all', 'locked' => false,
            ]);
        }
        $exec = User::factory()->create(['role' => Role::StoreExec, 'access_mode' => 'advanced'])->fresh(); // fresh: load is_active like a real login
        $role->users()->attach($exec->id, ['status' => 'active', 'starts_at' => now()->subMinute()]);
        app(AccessControlService::class)->flushUser($exec);

        $this->actingAs($exec);
        $this->get(route('grn.register'))->assertOk();   // "View GRN register" is the 2nd permission on this page
        $this->get(route('qc.queue'))->assertOk();
        $this->get(route('grn.check'))->assertForbidden(); // not given
        $this->get(route('inventory.stock'))->assertForbidden();
        $this->get(route('unloading.desk'))->assertOk()
            ->assertSeeInOrder(['Loading &amp; Unloading', 'More access', 'GRN Register', 'QC Queue'], false);

        // A Store Executive without the role: no extra pages, no extra menu.
        $plain = User::factory()->create(['role' => Role::StoreExec, 'access_mode' => 'advanced'])->fresh();
        $this->actingAs($plain)->get(route('grn.register'))->assertForbidden();
        $this->actingAs($plain)->get(route('unloading.desk'))->assertOk()->assertDontSee('More access');
    }
}
