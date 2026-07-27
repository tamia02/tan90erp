<?php

namespace App\Services\Access\Widgets;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\User;

class BrcRecipeWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        return [
            'metric' => Recipe::count() + Bom::count(),
            'caption' => 'Recipes and BOMs',
            'route' => 'tan90.brc.dashboard',
        ];
    }
}
