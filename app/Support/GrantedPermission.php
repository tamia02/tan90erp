<?php

namespace App\Support;

use App\Models\Access\AccessPermission;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\User;
use App\Services\Access\AccessControlService;

// Super Admin always; anyone else only when Super Admin has given them this
// permission (Access Control > People > Extra access, or through a role).
// Older "legacy" accounts skip the permission engine, so their direct
// extra-access grant is read here too.
class GrantedPermission
{
    public static function has(?User $user, string $key): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin() || app(AccessControlService::class)->can($user, $key)) {
            return true;
        }
        $permissionId = AccessPermission::where('key', $key)->value('id');

        return $permissionId !== null && AccessUserPermissionOverride::where('user_id', $user->id)
            ->where('permission_id', $permissionId)->where('allowed', true)->where('status', 'active')
            ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
