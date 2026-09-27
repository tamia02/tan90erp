<?php

namespace App\Services\Access\Widgets;

use App\Models\Tan90\MasterData\Vendor;
use App\Models\User;
use App\Services\Access\AccessControlService;

class MasterVendorWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => Vendor::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->count(),
            'caption' => 'Master-data vendors',
            'route' => 'tan90.master-data.index',
            'route_params' => ['vendors'],
        ];
    }
}
