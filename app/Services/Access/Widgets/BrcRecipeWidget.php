<?php

namespace App\Services\Access\Widgets;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\User;
use App\Services\Access\AccessControlService;

class BrcRecipeWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);

        return [
            'metric' => Recipe::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->count()
                + Bom::when($scope, fn ($q) => $q->whereIn('created_by', $scope))->count(),
            'caption' => 'Recipes and BOMs',
            'route' => 'tan90.brc.dashboard',
        ];
    }
}
