<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\BomLine;

/**
 * "BOM circular references are prohibited" (Codex prompt production rule).
 * Walks the sub-BOM graph (tan90_bom_lines.tan90_sub_bom_id) depth-first;
 * a cycle exists if the BOM we started from reappears anywhere below it.
 */
class CircularReferenceService
{
    public function wouldCreateCycle(Bom $parentBom, Bom $candidateSubBom): bool
    {
        return $this->containsBom($candidateSubBom, $parentBom->id, [$candidateSubBom->id]);
    }

    /** Check the current state of a BOM's own sub-tree for a cycle back to itself. */
    public function hasCycle(Bom $bom): bool
    {
        return $this->containsBom($bom, $bom->id, [$bom->id]);
    }

    private function containsBom(Bom $bom, int $targetBomId, array $visited): bool
    {
        $subBomIds = BomLine::query()
            ->whereHas('bomVersion', fn ($q) => $q->where('tan90_bom_id', $bom->id))
            ->where('line_type', 'sub_bom')
            ->whereNotNull('tan90_sub_bom_id')
            ->pluck('tan90_sub_bom_id')
            ->unique();

        foreach ($subBomIds as $subBomId) {
            if ((int) $subBomId === $targetBomId) {
                return true;
            }

            if (in_array($subBomId, $visited, true)) {
                continue; // already walked this branch, not the cycle we're checking for
            }

            $subBom = Bom::find($subBomId);
            if ($subBom && $this->containsBom($subBom, $targetBomId, [...$visited, $subBomId])) {
                return true;
            }
        }

        return false;
    }
}
