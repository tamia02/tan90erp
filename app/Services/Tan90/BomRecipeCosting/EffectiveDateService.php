<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Effective dates cannot overlap for same product, plant and object type"
 * (Codex prompt production rule). Plant is out of scope for Phase 1 (no
 * plant column on these versions yet), so this checks per product+object
 * type, which is the enforceable subset of the rule today.
 */
class EffectiveDateService
{
    public function recipeOverlaps(RecipeVersion $version): bool
    {
        if (! $version->effective_from) {
            return false;
        }

        return $this->overlapQuery(
            RecipeVersion::query()->where('tan90_recipe_id', $version->tan90_recipe_id),
            $version->id,
            $version->effective_from,
            $version->effective_to
        )->exists();
    }

    public function bomOverlaps(BomVersion $version): bool
    {
        if (! $version->effective_from) {
            return false;
        }

        return $this->overlapQuery(
            BomVersion::query()->where('tan90_bom_id', $version->tan90_bom_id),
            $version->id,
            $version->effective_from,
            $version->effective_to
        )->exists();
    }

    /**
     * Standard date-range-overlap predicate: two ranges overlap when each
     * range's start is on or before the other's end (a null end means "open
     * ended", i.e. it never closes off the overlap from that side).
     */
    private function overlapQuery(Builder $query, ?int $excludeId, string $from, ?string $to): Builder
    {
        return $query
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereIn('gate_status', ['released', 'mrp_ready'])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->where('effective_from', '<=', $to));
    }
}
