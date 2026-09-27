<?php

namespace App\Services\Access\Widgets;

use App\Models\GateEntry;
use App\Models\User;
use App\Services\Access\AccessControlService;

/**
 * Backward-compatible dashboard provider for installations whose widget
 * catalog still references the former gate-entry provider class.
 */
class GateEntriesWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => GateEntry::when($scope, fn ($query) => $query->whereIn('created_by', $scope))->count(),
            'caption' => 'Gate entries',
            'route' => 'gate-entries.index',
        ];
    }
}
