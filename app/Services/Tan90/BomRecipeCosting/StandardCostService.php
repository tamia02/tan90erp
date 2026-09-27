<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\CostRollup;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use Illuminate\Support\Facades\Auth;

/**
 * "Standard cost approval requires complete released BOM, recipe, routing
 * and QA specifications" (Codex prompt production rule).
 */
class StandardCostService
{
    public function __construct(
        private MrpReadinessService $mrpReadinessService,
        private AuditTrailService $auditTrailService,
    ) {
    }

    /** @return array{approved: bool, errors: string[]} */
    public function approve(CostSheet $costSheet): array
    {
        $readiness = $this->mrpReadinessService->check($costSheet->finishedGood);
        // Standard cost approval only needs release, not full MRP-ready expiry checks;
        // reuse the same master-completeness blockers but drop cost-sheet-specific ones.
        $errors = array_values(array_filter(
            $readiness['blockers'],
            fn ($b) => ! str_contains($b, 'standard cost sheet')
        ));

        if (! empty($errors)) {
            return ['approved' => false, 'errors' => $errors];
        }

        $latestRollup = CostRollup::where('tan90_finished_good_id', $costSheet->tan90_finished_good_id)
            ->where('cost_period', $costSheet->cost_period)
            ->latest('rolled_up_at')
            ->first();

        if (! $latestRollup) {
            return ['approved' => false, 'errors' => ['No cost roll-up exists yet for this period — run Cost Roll-up first.']];
        }

        $costSheet->update([
            'material_cost' => $latestRollup->material_cost,
            'labor_cost' => $latestRollup->labor_cost,
            'machine_cost' => $latestRollup->machine_cost,
            'utility_cost' => $latestRollup->utility_cost,
            'overhead_cost' => $latestRollup->overhead_cost,
            'total_standard_cost' => $latestRollup->total_cost,
            'approval_status' => 'approved',
            'updated_by' => Auth::id(),
        ]);

        $this->auditTrailService->log('STANDARD_COST_APPROVE', $costSheet, "Approved standard cost {$costSheet->total_standard_cost} for {$costSheet->code}.");

        return ['approved' => true, 'errors' => []];
    }
}
