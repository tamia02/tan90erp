<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;

/**
 * Enforces "recipe component percentages must total 100% within configurable
 * tolerance" (Codex prompt production rule). Tolerance is per-recipe
 * (tan90_recipes.formula_tolerance_percent), defaulting to 0.5%.
 */
class RecipeValidationService
{
    /** @return array{valid: bool, total: float, tolerance: float, errors: string[]} */
    public function validate(RecipeVersion $version): array
    {
        $recipe = $version->recipe ?? Recipe::find($version->tan90_recipe_id);
        $tolerance = (float) ($recipe->formula_tolerance_percent ?? 0.5);

        $total = round((float) $version->lines()->sum('percentage'), 4);
        $diff = abs($total - 100.0);
        $errors = [];

        if ($diff > $tolerance) {
            $errors[] = sprintf(
                'Component percentages total %.4f%%, outside the %.2f%% tolerance of 100%%.',
                $total,
                $tolerance
            );
        }

        if ($version->lines()->count() === 0) {
            $errors[] = 'Recipe version has no component lines.';
        }

        $duplicateComponentIds = $version->lines()
            ->reorder()
            ->selectRaw('tan90_component_id, COUNT(*) as cnt')
            ->groupBy('tan90_component_id')
            ->having('cnt', '>', 1)
            ->pluck('tan90_component_id');

        if ($duplicateComponentIds->isNotEmpty()) {
            $errors[] = 'Duplicate component lines are not allowed within one recipe version.';
        }

        return [
            'valid' => empty($errors),
            'total' => $total,
            'tolerance' => $tolerance,
            'errors' => $errors,
        ];
    }
}
