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

        $permissions = config('tan90_role_permissions.'.($user->role?->value ?? '').'.'.$type, []);

        return in_array('*', $permissions, true) || in_array($key, $permissions, true);
    }

    public static function requireExport(string $key): void
    {
        abort_unless(auth()->check() && self::can(auth()->user(), 'exports', $key), 403, 'Your role cannot export this dataset.');
    }
}
