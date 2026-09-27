<?php

namespace Tests\Feature;

use App\Enums\Role as AppRole;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\Tan90\MasterData\Permission;
use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminRoleCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_administrator_and_super_admin_aliases_merge_without_losing_assignments_or_grants(): void
    {
        $tanSuper = Role::where('code', 'ROLE-SUPER')->first()
            ?? Role::create(['code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'type' => 'System', 'data_scope' => 'All Entities', 'status' => 'active']);
        $tanAdmin = Role::create(['code' => 'ROLE-ADMIN', 'name' => 'Admin', 'type' => 'System', 'data_scope' => 'Inward only', 'status' => 'active']);
        $view = Permission::create(['key' => 'view', 'label' => 'View']);
        $export = Permission::create(['key' => 'export', 'label' => 'Export']);
        DB::table('tan90_role_permission')->insert([
            ['tan90_role_id' => $tanSuper->id, 'tan90_permission_id' => $view->id, 'allowed' => false, 'created_at' => now(), 'updated_at' => now()],
            ['tan90_role_id' => $tanAdmin->id, 'tan90_permission_id' => $view->id, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()],
            ['tan90_role_id' => $tanAdmin->id, 'tan90_permission_id' => $export->id, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $user = User::factory()->create(['role' => null]);
        DB::table('users')->where('id', $user->id)->update(['role' => 'Administrator']);
        UserProfile::create(['user_id' => $user->id, 'tan90_role_id' => $tanAdmin->id, 'employee_id' => 'MERGE-TEST-'.$user->id, 'department' => 'IT', 'all_locations' => false, 'mfa_status' => 'pending', 'status' => 'active']);

        $accessSuper = AccessRole::create(['code' => 'ACCESS-SUPER-ADMIN', 'name' => 'Super Admin', 'label' => 'Super Admin', 'level' => 1, 'hierarchy_level' => 1, 'role_kind' => 'system', 'is_system' => true, 'status' => 'active']);
        $accessAdmin = AccessRole::create(['code' => 'LEGACY-ADMIN', 'name' => 'Administrator', 'label' => 'Administrator', 'level' => 1, 'hierarchy_level' => 1, 'role_kind' => 'system', 'is_system' => true, 'status' => 'active']);
        $accessPermission = AccessPermission::create(['key' => 'test.export', 'module' => 'Test', 'screen' => 'Test', 'action' => 'export', 'label' => 'Test Export']);
        DB::table('access_role_permissions')->insert([
            ['role_id' => $accessSuper->id, 'permission_id' => $accessPermission->id, 'allowed' => false, 'effect' => 'deny', 'scope_type' => 'self', 'created_at' => now(), 'updated_at' => now()],
            ['role_id' => $accessAdmin->id, 'permission_id' => $accessPermission->id, 'allowed' => true, 'effect' => 'allow', 'scope_type' => 'self', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('access_user_roles')->insert([
            ['user_id' => $user->id, 'role_id' => $accessAdmin->id, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_09_24_120000_consolidate_admin_profiles_into_super_admin.php');
        $migration->up();

        $this->assertDatabaseMissing('tan90_roles', ['code' => 'ROLE-ADMIN']);
        $this->assertDatabaseHas('tan90_roles', ['id' => $tanSuper->id, 'code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'data_scope' => 'All Entities']);
        $this->assertSame($tanSuper->id, $user->tan90Profile()->value('tan90_role_id'));
        $this->assertTrue($tanSuper->fresh()->can('view'));
        $this->assertTrue($tanSuper->fresh()->can('export'));
        $this->assertSame(AppRole::Admin, $user->fresh()->role);
        $this->assertSame('Super Admin', AppRole::Admin->label());

        $this->assertDatabaseMissing('access_roles', ['code' => 'LEGACY-ADMIN']);
        $this->assertDatabaseHas('access_roles', ['id' => $accessSuper->id, 'code' => 'ACCESS-SUPER-ADMIN', 'name' => 'Super Admin', 'label' => 'Super Admin']);
        $this->assertDatabaseHas('access_role_permissions', ['role_id' => $accessSuper->id, 'permission_id' => $accessPermission->id, 'allowed' => true, 'effect' => 'allow', 'scope_type' => 'all']);
        $this->assertSame(1, DB::table('access_user_roles')->where('user_id', $user->id)->where('role_id', $accessSuper->id)->count());

        $this->seed(\Database\Seeders\Access\AccessControlSeeder::class);
        $this->assertDatabaseMissing('access_roles', ['code' => 'LEGACY-ADMIN']);
    }
}
