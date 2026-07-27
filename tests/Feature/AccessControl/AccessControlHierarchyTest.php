<?php

namespace Tests\Feature\AccessControl;

use App\Enums\Role;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessSavedView;
use App\Models\Access\AccessUserRoleAssignment;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessControlHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_can_open_access_control_and_workspace(): void
    {
        $user = User::where('email', 'superadmin@tan90.demo')->firstOrFail();

        $this->actingAs($user)->get(route('access.roles.index'))->assertOk()->assertSee('Manage Roles');
        $this->actingAs($user)->get(route('access.people.index'))->assertOk()->assertSee('Manage Users');
        $this->actingAs($user)->get(route('access.hierarchy.index'))->assertOk()->assertSee('Hierarchy Structure');
        $this->actingAs($user)->get(route('access.dashboard-builder.index'))->assertOk()->assertSee('Role Permission Dashboard Builder');
        $this->actingAs($user)->get(route('workspace.index'))->assertOk()->assertSee('My Workspace');
    }

    public function test_head_cannot_create_head_or_super_role(): void
    {
        $head = User::where('email', 'head.store@tan90.demo')->firstOrFail();

        $this->withoutMiddleware()->actingAs($head)->post(route('access.roles.store'), [
            'name' => 'Blocked Head',
            'label' => 'Head',
            'code' => 'BLOCKED-HEAD',
            'level' => AccessRole::LEVEL_HEAD,
            'vertical_id' => AccessRole::where('code', 'ACCESS-STORE-HEAD')->firstOrFail()->vertical_id,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_manager_cannot_create_manager_or_head_role(): void
    {
        $manager = User::where('email', 'manager.grn@tan90.demo')->firstOrFail();

        $this->withoutMiddleware()->actingAs($manager)->post(route('access.roles.store'), [
            'name' => 'Blocked Manager',
            'label' => 'Manager',
            'code' => 'BLOCKED-MANAGER',
            'level' => AccessRole::LEVEL_MANAGER,
            'vertical_id' => AccessRole::where('code', 'ACCESS-GRN-MANAGER')->firstOrFail()->vertical_id,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_manager_can_open_dashboard_builder_with_user_builder_grant(): void
    {
        $manager = User::where('email', 'manager.grn@tan90.demo')->firstOrFail();

        $this->actingAs($manager)->get(route('access.dashboard-builder.index'))->assertOk()->assertSee('Role Permission Dashboard Builder');
    }

    public function test_delegation_scope_ceiling_is_enforced(): void
    {
        $service = app(AccessControlService::class);
        $executive = User::where('email', 'executive.grnqc@tan90.demo')->firstOrFail();
        $head = User::where('email', 'head.store@tan90.demo')->firstOrFail();

        $this->assertFalse($service->canDelegate($executive, 'grn.records.view', ['scope_type' => 'team']));
        $this->assertTrue($service->canDelegate($head, 'grn.records.view', ['scope_type' => 'vertical']));
        $this->assertFalse($service->canDelegate($head, 'grn.records.view', ['scope_type' => 'all']));
    }

    public function test_expired_roles_do_not_count(): void
    {
        $user = User::where('email', 'executive.grnqc@tan90.demo')->firstOrFail();
        $role = AccessRole::where('code', 'ACCESS-GRNQC-EXEC')->firstOrFail();
        $user->accessRoles()->updateExistingPivot($role->id, ['expires_at' => now()->subMinute()]);
        AccessUserRoleAssignment::where('user_id', $user->id)->where('role_id', $role->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse(app(AccessControlService::class)->can($user, 'workspace.view'));
    }

    public function test_legacy_role_access_still_works_without_new_access_assignment(): void
    {
        $legacy = User::factory()->create(['role' => Role::Guard, 'is_active' => true]);

        $this->actingAs($legacy)->get(route('guard.dashboard'))->assertOk();
    }

    public function test_executive_workspace_combines_only_real_grn_and_qc_widgets(): void
    {
        $executive = User::where('email', 'executive.grnqc@tan90.demo')->firstOrFail();
        $widgets = app(AccessControlService::class)->widgetsFor($executive)->pluck('key')->all();

        $this->assertContains('grn_open_records', $widgets);
        $this->assertContains('qc_queue', $widgets);
        $this->assertNotContains('finance_claims', $widgets);
    }

    public function test_legacy_mode_ignores_new_access_engine(): void
    {
        $legacy = User::factory()->create(['role' => null, 'access_mode' => 'legacy', 'is_active' => true]);

        $this->assertFalse(app(AccessControlService::class)->can($legacy, 'workspace.view'));
    }

    public function test_direct_user_allow_and_deny_are_seeded(): void
    {
        $grnQc = User::where('email', 'executive.grnqc@tan90.demo')->firstOrFail();
        $readonly = User::where('email', 'executive.readonly@tan90.demo')->firstOrFail();

        $this->assertTrue(app(AccessControlService::class)->can($grnQc, 'qc.queue.view'));
        $this->assertDatabaseHas('access_user_permission_overrides', ['user_id' => $readonly->id, 'effect' => 'deny']);
    }

    public function test_saved_view_and_dashboard_demo_records_exist(): void
    {
        $this->assertTrue(AccessSavedView::where('key', 'grn-qc-exec-focused')->exists());
        $this->assertDatabaseHas('dashboard_templates', ['name' => 'GRN + QC Combined Dashboard', 'status' => 'published']);
    }

    public function test_demo_login_route_is_available_for_local_preview(): void
    {
        $this->assertTrue(Route::has('demo-login'));
        $this->get(route('login'))->assertOk()->assertSee('Explore Demo Accounts by Hierarchy');
    }
}
