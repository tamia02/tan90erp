<?php

namespace Tests\Feature\Tan90\MasterData\Concerns;

use App\Models\Tan90\MasterData\Permission;
use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal role/permission/profile bootstrap shared by the module's feature
 * tests, independent of Tan90MasterDataSeeder (which seeds full demo data).
 */
trait SeedsTan90Access
{
    protected function makeRole(string $code, array $grants, ?string $name = null): Role
    {
        foreach (Permission::CATALOG as $key => $label) {
            Permission::firstOrCreate(['key' => $key], ['label' => $label]);
        }

        $role = Role::firstOrCreate(['code' => $code], ['name' => $name ?? $code, 'type' => 'Operational', 'status' => 'active']);

        $sync = [];
        foreach (Permission::all() as $permission) {
            $sync[$permission->id] = ['allowed' => in_array($permission->key, $grants, true)];
        }
        $role->permissions()->sync($sync);

        return $role;
    }

    /**
     * Unlike userWithRole() (whose Role.name equals the test-only $roleCode),
     * this creates a Role whose *name* is exactly $roleName - needed to satisfy
     * ApprovalService's step-role gate, which matches on Role.name (e.g. "QC",
     * "Finance") rather than an internal code.
     */
    protected function userWithNamedRole(string $roleName, array $grants): User
    {
        $role = $this->makeRole('ROLE-' . strtoupper(str_replace(' ', '-', $roleName)) . '-TEST', $grants, $roleName);

        $user = User::factory()->create(['password' => Hash::make('password')]);

        UserProfile::create([
            'user_id' => $user->id,
            'tan90_role_id' => $role->id,
            'employee_id' => 'T-' . $user->id,
            'mfa_status' => 'enabled',
            'status' => 'active',
            'all_locations' => true,
        ]);

        return $user->refresh();
    }

    protected function userWithRole(string $roleCode, array $grants, array $profileOverrides = []): User
    {
        $role = $this->makeRole($roleCode, $grants);

        $user = User::factory()->create(['password' => Hash::make('password')]);

        UserProfile::create(array_merge([
            'user_id' => $user->id,
            'tan90_role_id' => $role->id,
            'employee_id' => 'T-' . $user->id,
            'mfa_status' => 'enabled',
            'status' => 'active',
            'all_locations' => true,
        ], $profileOverrides));

        return $user->refresh();
    }

    protected function superAdmin(): User
    {
        return $this->userWithRole('ROLE-SUPER-TEST', ['view', 'create', 'edit', 'delete', 'approve', 'export', 'settings']);
    }

    protected function masterDataManager(): User
    {
        return $this->userWithRole('ROLE-MDM-TEST', ['view', 'create', 'edit', 'approve', 'export']);
    }

    protected function auditor(): User
    {
        return $this->userWithRole('ROLE-AUDIT-TEST', ['view', 'export']);
    }

    protected function plantUser(int $plantId = null, int $locationId = null): User
    {
        return $this->userWithRole('ROLE-PLANT-TEST', ['view', 'export'], [
            'all_locations' => false,
            'assigned_plant_id' => $plantId,
            'assigned_location_id' => $locationId,
        ]);
    }
}
