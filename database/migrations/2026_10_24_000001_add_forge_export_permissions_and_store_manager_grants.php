<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        'forge.plan.export', 'forge.workorder.export', 'forge.machine.export',
        'forge.wastage.export', 'forge.finalqc.export', 'forge.batch.export',
        'flow.inventory.view', 'flow.inventory.putaway',
    ];

    public function up(): void
    {
        $permissions = collect(app(PermissionRegistry::class)->permissions())->whereIn('key', self::KEYS);
        foreach ($permissions as $permission) {
            $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types'] ?? ['all']);
            DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
        }

        $roleIds = DB::table('access_roles')->where('code', 'LEGACY-STOREMANAGER')->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $permissionIds = DB::table('access_permissions')->whereIn('key', self::KEYS)->pluck('id');
        $now = now();
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('access_role_permissions')->updateOrInsert([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ], [
                    'allowed' => true,
                    'effect' => 'allow',
                    'delegable' => true,
                    'scope_type' => 'all',
                    'max_scope_type' => 'all',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('access_permissions')->whereIn('key', self::KEYS)->pluck('id');
        DB::table('access_role_permissions')->whereIn('permission_id', $permissionIds)->whereIn('role_id', function ($query) {
            $query->select('id')->from('access_roles')->where('code', 'LEGACY-STOREMANAGER');
        })->delete();
        DB::table('access_permissions')->whereIn('key', array_slice(self::KEYS, 0, 6))->delete();
    }
};
