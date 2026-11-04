<?php

namespace App\Services\Access\Widgets;

use App\Models\User;
use App\Services\Access\AccessControlService;

/** Supports dashboard catalogs that still contain the legacy account widget. */
class AccountOverviewWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => User::query()
                ->where('is_active', true)
                ->when($scope, fn ($query) => $query->whereIn('id', $scope))
                ->count(),
            'caption' => 'Active user accounts',
            'route' => 'admin.users',
        ];
    }
}
