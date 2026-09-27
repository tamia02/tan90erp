<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Models\Tan90\BomRecipeCosting\CostRate;
use App\Models\Tan90\BomRecipeCosting\CostRollup;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Cost roll-up must use effective component, labor, machine, utility and
 * overhead rates. Cost roll-up is idempotent by product + revision +
 * cost-period + input-hash" (Codex prompt production rules).
 */
class CostRollupService
{
    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    public function rollUp(FinishedGood $finishedGood, BomVersion $bomVersion, string $costPeriod): CostRollup
    {
        return DB::transaction(function () use ($finishedGood, $bomVersion, $costPeriod) {
            $lines = $bomVersion->lines()->with('component')->get();
            $routing = $finishedGood->routings()->with('operations.workCenter')->first();

            $materialCost = $lines->sum(function ($line) {
                $rate = $this->effectiveRate('material', $line->tan90_component_id) ?? (float) ($line->component?->standard_cost ?? 0);

                return (float) $line->quantity * (1 + (float) $line->wastage_percent / 100) * $rate;
            });

            $laborCost = 0.0;
            $machineCost = 0.0;
            $overheadCost = 0.0;
            foreach ($routing?->operations ?? [] as $operation) {
                $hours = (float) $operation->run_time_minutes / 60;
                $laborCost += $hours * (float) ($operation->workCenter?->labor_rate ?? 0);
                $machineCost += $hours * (float) ($operation->workCenter?->machine_rate ?? 0);
                $overheadCost += $hours * (float) ($operation->workCenter?->overhead_rate ?? 0);
            }

            $utilityRate = $this->effectiveRate('utility', null) ?? 0.0;
            $utilityCost = $utilityRate * (float) ($routing?->operations->sum('run_time_minutes') ?? 0) / 60;

            $totalCost = round($materialCost + $laborCost + $machineCost + $utilityCost + $overheadCost, 4);

            $inputHash = hash('sha256', json_encode([
                'bom_version_id' => $bomVersion->id,
                'bom_updated_at' => optional($bomVersion->updated_at)->toIso8601String(),
                'cost_period' => $costPeriod,
                'material' => $materialCost,
                'labor' => $laborCost,
                'machine' => $machineCost,
                'utility' => $utilityCost,
                'overhead' => $overheadCost,
            ]));

            $existing = CostRollup::where('tan90_finished_good_id', $finishedGood->id)
                ->where('tan90_bom_version_id', $bomVersion->id)
                ->where('cost_period', $costPeriod)
                ->where('input_hash', $inputHash)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing; // idempotent: identical inputs already rolled up
            }

            $rollup = CostRollup::create([
                'code' => 'CR-' . strtoupper(Str::random(8)),
                'tan90_finished_good_id' => $finishedGood->id,
                'tan90_bom_version_id' => $bomVersion->id,
                'cost_period' => $costPeriod,
                'input_hash' => $inputHash,
                'material_cost' => round($materialCost, 4),
                'labor_cost' => round($laborCost, 4),
                'machine_cost' => round($machineCost, 4),
                'utility_cost' => round($utilityCost, 4),
                'overhead_cost' => round($overheadCost, 4),
                'total_cost' => $totalCost,
                'status' => 'completed',
                'rolled_up_at' => now(),
                'rolled_up_by' => Auth::id(),
            ]);

            $this->auditTrailService->log('COST_ROLLUP', $rollup, "Rolled up cost for {$finishedGood->code} / {$costPeriod}: {$totalCost}.");

            return $rollup;
        });
    }

    private function effectiveRate(string $rateType, ?int $referenceId): ?float
    {
        $query = CostRate::query()->effectiveOn($rateType);
        if ($referenceId !== null) {
            $query->where('reference_id', $referenceId);
        }

        return $query->value('rate') !== null ? (float) $query->value('rate') : null;
    }
}
