<?php

namespace App\Services\Access\Widgets;

use App\Models\QcResult;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Services\Access\AccessControlService;

class QcQueueWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        // ValidationIssue has no user attribution of its own - it's scoped
        // via the gate entry it was raised against.
        $issues = ValidationIssue::where('status', 'open')
            ->when($scope, fn ($q) => $q->whereHas('gateEntry', fn ($g) => $g->whereIn('created_by', $scope)))
            ->count();
        $results = QcResult::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->count();

        return [
            'metric' => $issues + $results,
            'caption' => 'QC checks and open validation issues',
            'route' => 'qc.queue',
        ];
    }
}
