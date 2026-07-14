<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\FinishedGood;

/**
 * "MRP readiness is blocked when any required master is draft, expired or
 * unmapped" (Codex prompt production rule).
 */
class MrpReadinessService
{
    /** @return array{ready: bool, blockers: string[]} */
    public function check(FinishedGood $finishedGood): array
    {
        $blockers = [];

        $recipe = $finishedGood->recipes()->first();
        $recipeVersion = $recipe?->currentVersion;
        if (! $recipe) {
            $blockers[] = 'No recipe mapped to this finished good.';
        } elseif (! $recipeVersion || ! in_array($recipeVersion->gate_status, ['released', 'mrp_ready'], true)) {
            $blockers[] = 'Recipe is not released (' . ($recipeVersion?->gate_status ?? 'unmapped') . ').';
        } elseif ($recipeVersion->effective_to && now()->toDateString() > $recipeVersion->effective_to) {
            $blockers[] = 'Recipe revision has expired.';
        }

        $bom = $finishedGood->boms()->where('bom_type', 'production')->first();
        $bomVersion = $bom?->currentVersion;
        if (! $bom) {
            $blockers[] = 'No production BOM mapped to this finished good.';
        } elseif (! $bomVersion || ! in_array($bomVersion->gate_status, ['released', 'mrp_ready'], true)) {
            $blockers[] = 'BOM is not released (' . ($bomVersion?->gate_status ?? 'unmapped') . ').';
        } elseif ($bomVersion->effective_to && now()->toDateString() > $bomVersion->effective_to) {
            $blockers[] = 'BOM revision has expired.';
        }

        $routing = $finishedGood->routings()->where('approval_status', 'approved')->first();
        if (! $routing) {
            $blockers[] = 'No approved routing mapped to this finished good.';
        }

        $costSheet = $finishedGood->costSheets()->where('approval_status', 'approved')->latest('cost_period')->first();
        if (! $costSheet) {
            $blockers[] = 'No approved standard cost sheet for this finished good.';
        }

        return ['ready' => empty($blockers), 'blockers' => $blockers];
    }
}
