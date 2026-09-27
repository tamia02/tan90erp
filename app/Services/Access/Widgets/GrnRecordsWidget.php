<?php

namespace App\Services\Access\Widgets;

use App\Models\GrnRecord;
use App\Models\User;
use App\Services\Access\AccessControlService;

class GrnRecordsWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => GrnRecord::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->count(),
            'caption' => 'GRN rows in register',
            'route' => 'grn.register',
        ];
    }
}
