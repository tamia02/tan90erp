<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\BomLine;
use App\Models\Tan90\BomRecipeCosting\Component;
use App\Models\Tan90\BomRecipeCosting\RecipeLine;

class WhereUsedService
{
    /** Recipes and BOMs a component appears in, across all versions. */
    public function forComponent(Component $component): array
    {
        return [
            'recipe_lines' => RecipeLine::with('recipeVersion.recipe')
                ->where('tan90_component_id', $component->id)
                ->get(),
            'bom_lines' => BomLine::with('bomVersion.bom')
                ->where('tan90_component_id', $component->id)
                ->get(),
        ];
    }

    /** Parent BOMs that reference this BOM as a nested/packaging sub-BOM. */
    public function forSubBom(Bom $bom): array
    {
        return BomLine::with('bomVersion.bom')
            ->where('tan90_sub_bom_id', $bom->id)
            ->get()
            ->all();
    }
}
