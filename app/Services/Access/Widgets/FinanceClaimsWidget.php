<?php

namespace App\Services\Access\Widgets;

use App\Models\FinanceRecord;
use App\Models\User;
use App\Services\Access\AccessControlService;

class FinanceClaimsWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => FinanceRecord::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->sum('final_payable'),
            'caption' => 'Final payable value',
            'route' => 'finance.claims',
            'format' => 'currency',
        ];
    }
}
