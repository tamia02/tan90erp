<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = collect(app(PermissionRegistry::class)->permissions())
            ->where('module', 'Forge')
            ->pluck('key')
            ->map(fn (string $key) => DB::table('access_permissions')->where('key', $key)->value('id'))
            ->filter()
            ->values();

        $roleIds = DB::table('access_roles')->where('code', 'LEGACY-STOREMANAGER')->pluck('id');
        $now = now();

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('access_role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
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
        $permissionIds = collect(app(PermissionRegistry::class)->permissions())
            ->where('module', 'Forge')
            ->pluck('key')
            ->map(fn (string $key) => DB::table('access_permissions')->where('key', $key)->value('id'))
            ->filter();

        DB::table('access_role_permissions')
            ->whereIn('role_id', DB::table('access_roles')->select('id')->where('code', 'LEGACY-STOREMANAGER'))
            ->whereIn('permission_id', $permissionIds)
            ->update(['allowed' => false, 'effect' => 'inherit', 'scope_type' => 'self', 'max_scope_type' => 'self', 'updated_at' => now()]);
    }
};
