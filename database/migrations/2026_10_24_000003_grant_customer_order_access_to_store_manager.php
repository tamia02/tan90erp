<?php

use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION_KEYS = [
        'flow.order.view',
        'flow.order.create',
        'flow.order.release',
    ];

    public function up(): void
    {
        $permissions = collect(app(PermissionRegistry::class)->permissions())
            ->whereIn('key', self::PERMISSION_KEYS)
            ->keyBy('key');
        $now = now();

        foreach (DB::table('access_roles')->where('code', 'LEGACY-STOREMANAGER')->pluck('id') as $roleId) {
            foreach (self::PERMISSION_KEYS as $key) {
                $permission = $permissions->get($key);
                $permissionId = DB::table('access_permissions')->where('key', $key)->value('id');

                if (! $permissionId && $permission) {
                    $permission['allowed_scope_types'] = json_encode($permission['allowed_scope_types'] ?? ['all']);
                    DB::table('access_permissions')->insertOrIgnore($permission + ['created_at' => $now, 'updated_at' => $now]);
                    $permissionId = DB::table('access_permissions')->where('key', $key)->value('id');
                }

                if ($permissionId) {
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
    }

    public function down(): void
    {
        $permissionIds = DB::table('access_permissions')
            ->whereIn('key', self::PERMISSION_KEYS)
            ->pluck('id');

        DB::table('access_role_permissions')
            ->whereIn('role_id', DB::table('access_roles')->select('id')->where('code', 'LEGACY-STOREMANAGER'))
            ->whereIn('permission_id', $permissionIds)
            ->update([
                'allowed' => false,
                'effect' => 'inherit',
                'scope_type' => 'self',
                'max_scope_type' => 'self',
                'updated_at' => now(),
            ]);
    }
};
