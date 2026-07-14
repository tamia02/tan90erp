<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\CostSheet;

class ActualCostService
{
    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    /**
     * Records actual costs by type and writes a CostVariance row per type
     * that has both a standard and actual figure to compare.
     *
     * @param array<string, float> $actualByType e.g. ['material' => 1200.0, 'labor' => 300.0, ...]
     */
    public function recordActual(CostSheet $costSheet, array $actualByType): void
    {
        $standardByType = [
            'material' => (float) $costSheet->material_cost,
            'labor' => (float) $costSheet->labor_cost,
            'machine' => (float) $costSheet->machine_cost,
            'utility' => (float) $costSheet->utility_cost,
            'overhead' => (float) $costSheet->overhead_cost,
        ];

        $totalActual = array_sum($actualByType);

        foreach ($actualByType as $type => $actual) {
            $standard = $standardByType[$type] ?? 0.0;
            $variance = $actual - $standard;

            $costSheet->variances()->create([
                'variance_type' => $type,
                'standard_cost' => $standard,
                'actual_cost' => $actual,
                'variance_amount' => round($variance, 4),
                'variance_percent' => $standard != 0.0 ? round(($variance / $standard) * 100, 2) : 0.0,
            ]);
        }

        $costSheet->update(['total_actual_cost' => round($totalActual, 4)]);

        $this->auditTrailService->log('ACTUAL_COST_RECORD', $costSheet, "Recorded actual cost {$totalActual} for {$costSheet->code}.");
    }
}
