<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use Illuminate\Support\Facades\DB;

/**
 * "Released recipe/BOM/routing/cost standard is immutable. Any change
 * creates a new revision and Engineering Change Order" (Codex prompt
 * production rule).
 */
class RevisionService
{
    public function __construct(
        private EngineeringChangeService $engineeringChangeService,
        private AuditTrailService $auditTrailService,
    ) {
    }

    public function newRecipeRevision(Recipe $recipe, string $reason, ?string $description = null): RecipeVersion
    {
        return DB::transaction(function () use ($recipe, $reason, $description) {
            // Lock the recipe's version sequence to prevent two concurrent
            // requests from both minting revision N+1.
            $current = RecipeVersion::where('tan90_recipe_id', $recipe->id)
                ->lockForUpdate()
                ->orderByDesc('revision_number')
                ->first();

            $nextNumber = ($current?->revision_number ?? 0) + 1;

            $eco = null;
            if ($current && in_array($current->gate_status, ['released', 'mrp_ready'], true)) {
                $eco = $this->engineeringChangeService->raise('recipe', $current->id, $reason, $description);
                $current->update(['gate_status' => 'superseded', 'is_current' => false]);
            } elseif ($current) {
                $current->update(['is_current' => false]);
            }

            $new = RecipeVersion::create([
                'tan90_recipe_id' => $recipe->id,
                'revision_code' => 'R' . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT),
                'revision_number' => $nextNumber,
                'gate_status' => 'draft',
                'is_current' => true,
                'tan90_engineering_change_order_id' => $eco?->id,
            ]);

            if ($current) {
                foreach ($current->lines as $line) {
                    $new->lines()->create($line->only([
                        'tan90_component_id', 'sequence', 'percentage', 'quantity', 'uom', 'wastage_percent', 'is_alternate',
                    ]));
                }
            }

            $this->auditTrailService->log('REVISION_CREATE', $new, "Created recipe revision {$new->revision_code}." . ($eco ? " ECO {$eco->code} raised for superseded revision." : ''));

            return $new;
        });
    }

    public function newBomRevision(Bom $bom, string $reason, ?string $description = null): BomVersion
    {
        return DB::transaction(function () use ($bom, $reason, $description) {
            $current = BomVersion::where('tan90_bom_id', $bom->id)
                ->lockForUpdate()
                ->orderByDesc('revision_number')
                ->first();

            $nextNumber = ($current?->revision_number ?? 0) + 1;

            $eco = null;
            if ($current && in_array($current->gate_status, ['released', 'mrp_ready'], true)) {
                $eco = $this->engineeringChangeService->raise('bom', $current->id, $reason, $description);
                $current->update(['gate_status' => 'superseded', 'is_current' => false]);
            } elseif ($current) {
                $current->update(['is_current' => false]);
            }

            $new = BomVersion::create([
                'tan90_bom_id' => $bom->id,
                'revision_code' => 'R' . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT),
                'revision_number' => $nextNumber,
                'gate_status' => 'draft',
                'is_current' => true,
                'tan90_engineering_change_order_id' => $eco?->id,
            ]);

            if ($current) {
                foreach ($current->lines as $line) {
                    $new->lines()->create($line->only([
                        'line_type', 'tan90_component_id', 'tan90_sub_bom_id', 'sequence', 'quantity', 'uom', 'wastage_percent', 'is_alternate',
                    ]));
                }
            }

            $this->auditTrailService->log('REVISION_CREATE', $new, "Created BOM revision {$new->revision_code}." . ($eco ? " ECO {$eco->code} raised for superseded revision." : ''));

            return $new;
        });
    }

    /** True once released/mrp_ready — direct line edits must go through a new revision instead. */
    public function isImmutable(RecipeVersion|BomVersion $version): bool
    {
        return in_array($version->gate_status, ['released', 'mrp_ready'], true);
    }
}
