<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\RecipeVersion;

/**
 * "Batch scaling must preserve ratio and apply line wastage separately"
 * (Codex prompt production rule). Scaling never mutates the released
 * recipe version's lines — it returns a computed preview for the caller
 * (a batch sizing screen) to display or hand to production.
 */
class RecipeScalingService
{
    /**
     * @return array<int, array{component_id:int, base_percentage:float, scaled_quantity:float, wastage_quantity:float, total_quantity:float, uom:?string}>
     */
    public function scale(RecipeVersion $version, float $batchSize): array
    {
        return $version->lines()->with('component')->orderBy('sequence')->get()
            ->map(function ($line) use ($batchSize) {
                $baseQuantity = $batchSize * ((float) $line->percentage / 100);
                $wastageQuantity = $baseQuantity * ((float) $line->wastage_percent / 100);

                return [
                    'component_id' => $line->tan90_component_id,
                    'component_name' => $line->component?->masking_code,
                    'base_percentage' => (float) $line->percentage,
                    'scaled_quantity' => round($baseQuantity, 4),
                    'wastage_quantity' => round($wastageQuantity, 4),
                    'total_quantity' => round($baseQuantity + $wastageQuantity, 4),
                    'uom' => $line->uom ?? $line->component?->uom,
                ];
            })
            ->all();
    }
}
