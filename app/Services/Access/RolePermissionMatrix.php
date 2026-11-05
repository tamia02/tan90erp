<?php

namespace App\Services\Access;

final class RolePermissionMatrix
{
    public const HIDDEN_PERMISSION_KEYS = ['access_control.module.access'];

    public static function actionFor(string $category, ?string $action): ?string
    {
        if (in_array($category, ['module', 'page', 'dashboard', 'section'], true)
            && in_array($action, ['access', 'view'], true)) {
            return 'read';
        }

        if ($category === 'field' && $action === 'view') {
            return 'read';
        }

        if ($category === 'action' && $action === 'export') {
            return 'export';
        }

        if ($category === 'action' && in_array($action, ['create', 'update', 'manage', 'record', 'perform', 'submit'], true)) {
            return 'write';
        }

        return null;
    }

    public static function isHidden(string $key): bool
    {
        return in_array($key, self::HIDDEN_PERMISSION_KEYS, true);
    }
}
