<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\CostSimulation;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CostSimulationService
{
    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    /**
     * Non-persisted what-if: applies percentage deltas to a base cost
     * sheet's components without touching the real cost sheet or rollup.
     *
     * @param array<string, float> $adjustments e.g. ['material' => 5, 'utility' => -10] (percent deltas)
     */
    public function simulate(FinishedGood $finishedGood, CostSheet $baseCostSheet, string $scenarioName, array $adjustments, ?float $sellingPrice = null): CostSimulation
    {
        $components = [
            'material' => (float) $baseCostSheet->material_cost,
            'labor' => (float) $baseCostSheet->labor_cost,
            'machine' => (float) $baseCostSheet->machine_cost,
            'utility' => (float) $baseCostSheet->utility_cost,
            'overhead' => (float) $baseCostSheet->overhead_cost,
        ];

        $simulatedTotal = 0.0;
        foreach ($components as $type => $amount) {
            $deltaPercent = $adjustments[$type] ?? 0.0;
            $simulatedTotal += $amount * (1 + $deltaPercent / 100);
        }

        $marginPercent = null;
        if ($sellingPrice && $sellingPrice > 0) {
            $marginPercent = round((($sellingPrice - $simulatedTotal) / $sellingPrice) * 100, 2);
        }

        $simulation = CostSimulation::create([
            'code' => 'SIM-' . strtoupper(Str::random(8)),
            'tan90_finished_good_id' => $finishedGood->id,
            'tan90_cost_sheet_id' => $baseCostSheet->id,
            'scenario_name' => $scenarioName,
            'adjustments' => $adjustments,
            'simulated_total_cost' => round($simulatedTotal, 4),
            'margin_percent' => $marginPercent,
            'created_by' => Auth::id(),
        ]);

        $this->auditTrailService->log('COST_SIMULATE', $simulation, "Simulated scenario '{$scenarioName}' for {$finishedGood->code}: {$simulation->simulated_total_cost}.");

        return $simulation;
    }
}
