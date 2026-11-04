<?php

namespace App\Services\Access;

use App\Enums\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A GRN-side Super Admin (users.role = admin, super_admin flag on) is created
// with access_mode 'legacy', which the Access Control engine denies outright -
// so they could not open Access Control, Forge, Flow or Workspace at all.
// This switches those accounts onto the advanced engine (where the
// super_admin flag is a full bypass) and links them to ACCESS-SUPER-ADMIN.
// Additive and idempotent: it never removes a role or touches other users.
class SuperAdminAccessSync
{
    public function syncAll(): int
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'access_mode')) {
            return 0;
        }

        $userIds = DB::table('users')
            ->where('role', Role::Admin->value)
            ->where('super_admin', true)
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->syncUser((int) $userId);
        }

        return $userIds->count();
    }

    public function syncUser(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update(['access_mode' => 'advanced']);

        $superRoleId = Schema::hasTable('access_roles')
            ? DB::table('access_roles')->where('code', 'ACCESS-SUPER-ADMIN')->value('id')
            : null;

        if ($superRoleId) {
            if (Schema::hasTable('access_user_roles')) {
                DB::table('access_user_roles')->insertOrIgnore([
                    'user_id' => $userId, 'role_id' => $superRoleId, 'status' => 'active',
                    'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // The unique key includes nullable scope columns, so MySQL would
            // not stop a duplicate unscoped row - check explicitly instead.
            $hasAssignment = Schema::hasTable('access_user_role_assignments')
                && DB::table('access_user_role_assignments')->where('user_id', $userId)->where('role_id', $superRoleId)->exists();
            if (Schema::hasTable('access_user_role_assignments') && ! $hasAssignment) {
                DB::table('access_user_role_assignments')->insert([
                    'user_id' => $userId, 'role_id' => $superRoleId, 'status' => 'active',
                    'is_primary' => true, 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Cache::forget("access:permissions:{$userId}");
    }
}
