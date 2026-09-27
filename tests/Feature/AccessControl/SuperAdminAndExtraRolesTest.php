<?php

namespace Tests\Feature\AccessControl;

use App\Enums\Role;
use App\Models\Access\AccessRole;
use App\Models\User;
use App\Services\Access\SuperAdminAccessSync;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuperAdminAndExtraRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    private function grnSuperAdmin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'super_admin' => true]);
    }

    public function test_grn_super_admin_is_locked_out_of_access_control_until_synced(): void
    {
        $admin = $this->grnSuperAdmin();
        $this->assertSame('legacy', $admin->fresh()->access_mode);
        $this->actingAs($admin)->get(route('access.roles.index'))->assertForbidden();

        app(SuperAdminAccessSync::class)->syncAll();
        $admin->refresh();

        $this->assertSame('advanced', $admin->access_mode);
        $this->assertTrue($admin->accessRoles()->where('code', 'ACCESS-SUPER-ADMIN')->exists());
        $this->actingAs($admin)->get(route('access.roles.index'))->assertOk();
        $this->actingAs($admin)->get(route('access.people.index'))->assertOk();
        $this->actingAs($admin)->get(route('forge.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('flow.dashboard'))->assertOk();
    }

    public function test_super_admin_sync_is_idempotent_and_ignores_non_super_admins(): void
    {
        $admin = $this->grnSuperAdmin();
        $guard = User::factory()->create(['role' => Role::Guard]);

        app(SuperAdminAccessSync::class)->syncAll();
        app(SuperAdminAccessSync::class)->syncAll();

        $superRoleId = AccessRole::where('code', 'ACCESS-SUPER-ADMIN')->value('id');
        $this->assertSame(1, \DB::table('access_user_roles')->where('user_id', $admin->id)->where('role_id', $superRoleId)->count());
        $this->assertSame(1, \DB::table('access_user_role_assignments')->where('user_id', $admin->id)->where('role_id', $superRoleId)->count());
        $this->assertSame('legacy', $guard->fresh()->access_mode);
    }

    public function test_super_admin_sidebar_groups_every_module(): void
    {
        $admin = $this->grnSuperAdmin();
        app(SuperAdminAccessSync::class)->syncAll();

        $this->actingAs($admin->fresh())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Super Admin', 'All Modules', 'BOM, Recipe &amp; Costing', 'Forge (Manufacturing)', 'Access Control', 'Access Roles', 'People'], false);
    }

    public function test_extra_roles_open_their_screens_and_sidebar_sections(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $this->actingAs($manager)->get(route('qc.queue'))->assertForbidden();

        $manager->extraRoleRecords()->create(['role' => Role::Qc]);
        $manager->refresh();

        $this->assertTrue($manager->holdsRole(Role::Qc));
        $this->assertTrue($manager->holdsRole(Role::StoreManager));
        $this->assertFalse($manager->holdsRole(Role::Finance));
        $this->actingAs($manager)->get(route('qc.queue'))->assertOk();
        $this->actingAs($manager)->get(route('finance.dashboard'))->assertForbidden();
        $this->actingAs($manager)->get(route('grn.dashboard'))
            ->assertOk()
            ->assertSee('QC Queue')
            ->assertSee(Role::Qc->label());
    }

    public function test_single_role_user_is_unchanged(): void
    {
        $qc = User::factory()->create(['role' => Role::Qc]);

        $this->assertSame([Role::Qc], $qc->operationalRoles());
        $this->actingAs($qc)->get(route('qc.queue'))->assertOk();
        $this->actingAs($qc)->get(route('grn.dashboard'))->assertForbidden();
    }

    public function test_super_admin_saves_job_roles_from_people_screen(): void
    {
        $admin = $this->grnSuperAdmin();
        app(SuperAdminAccessSync::class)->syncAll();
        $person = User::factory()->create(['role' => Role::StoreManager]);

        $this->actingAs($admin->fresh())
            ->post(route('access.people.job-roles', $person), ['main_role' => 'storeManager', 'extra_roles' => ['storeExec', 'qc', 'storeManager']])
            ->assertRedirect();

        $person->refresh();
        $this->assertSame(Role::StoreManager, $person->role);
        $this->assertEqualsCanonicalizing([Role::StoreExec, Role::Qc], $person->extraRoles());

        $this->actingAs($admin->fresh())
            ->post(route('access.people.job-roles', $person), ['main_role' => 'storeManager', 'extra_roles' => ['qc']])
            ->assertRedirect();
        $this->assertSame([Role::Qc], $person->fresh()->extraRoles());
    }

    public function test_extra_roles_need_a_main_role_and_cannot_be_vendor_or_admin(): void
    {
        $admin = $this->grnSuperAdmin();
        app(SuperAdminAccessSync::class)->syncAll();
        $person = User::factory()->create(['role' => null]);

        $this->actingAs($admin->fresh())
            ->post(route('access.people.job-roles', $person), ['main_role' => '', 'extra_roles' => ['qc']])
            ->assertSessionHasErrors('main_role');
        $this->actingAs($admin->fresh())
            ->post(route('access.people.job-roles', $person), ['main_role' => 'qc', 'extra_roles' => ['vendor']])
            ->assertSessionHasErrors('extra_roles.0');
        $this->assertSame([], $person->fresh()->extraRoles());
    }

    public function test_non_super_admin_cannot_change_job_roles(): void
    {
        $head = User::where('email', 'head.store@tan90.demo')->firstOrFail();
        $person = User::factory()->create(['role' => Role::StoreExec]);

        $this->actingAs($head)
            ->post(route('access.people.job-roles', $person), ['main_role' => 'storeExec', 'extra_roles' => ['finance']])
            ->assertForbidden();
        $this->assertSame([], $person->fresh()->extraRoles());
    }

    public function test_access_control_role_can_be_removed_but_not_own_super_admin(): void
    {
        $admin = $this->grnSuperAdmin();
        app(SuperAdminAccessSync::class)->syncAll();
        $admin = $admin->fresh();
        $person = User::factory()->create(['access_mode' => 'advanced']);
        $role = AccessRole::where('code', 'ACCESS-QC-EXEC')->firstOrFail();
        \DB::table('access_user_roles')->insert(['user_id' => $person->id, 'role_id' => $role->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->delete(route('access.people.remove-role', [$person, $role]))->assertRedirect();
        $this->assertFalse($person->accessRoles()->where('access_roles.id', $role->id)->exists());

        $super = AccessRole::where('code', 'ACCESS-SUPER-ADMIN')->firstOrFail();
        $response = $this->actingAs($admin)->delete(route('access.people.remove-role', [$admin, $super]));
        $this->assertTrue($response->isClientError());
        $this->assertTrue($admin->accessRoles()->where('access_roles.id', $super->id)->exists());
    }

    public function test_extra_store_exec_role_can_confirm_putaway_action_gate(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $this->assertFalse($manager->holdsRole(Role::StoreExec));

        $manager->extraRoleRecords()->create(['role' => Role::StoreExec]);
        $this->actingAs($manager->fresh())->get(route('unloading.putaway'))->assertOk()->assertDontSee('Only the Store Executive');
    }
}
