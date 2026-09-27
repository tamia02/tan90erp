<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

final class RoleCapability
{
    public static function can(User $user, string $type, string $key): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Union across the main role and any extra roles.
        foreach ($user->operationalRoles() as $role) {
            $permissions = config('tan90_role_permissions.'.$role->value.'.'.$type, []);
            if (in_array('*', $permissions, true) || in_array($key, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public static function requireExport(string $key): void
    {
        abort_unless(auth()->check() && self::can(auth()->user(), 'exports', $key), 403, 'Your role cannot export this dataset.');
    }
}
