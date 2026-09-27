<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Services\Tan90\BomRecipeCosting\ActualCostService;
use App\Services\Tan90\BomRecipeCosting\CostRollupService;
use App\Services\Tan90\BomRecipeCosting\CostSimulationService;
use App\Services\Tan90\BomRecipeCosting\StandardCostService;
use Illuminate\Http\Request;

class CostingController extends Controller
{
    public function __construct(
        private readonly CostRollupService $costRollup,
        private readonly StandardCostService $standardCost,
        private readonly ActualCostService $actualCost,
        private readonly CostSimulationService $costSimulation,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', CostSheet::class);

        $costSheets = CostSheet::with('finishedGood')
            ->when($request->filled('cost_period'), fn ($q) => $q->where('cost_period', $request->string('cost_period')))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('tan90.brc.costing.index', compact('costSheets'));
    }

    public function show(int $costSheet)
    {
        $costSheet = CostSheet::with(['finishedGood', 'variances', 'simulations'])->findOrFail($costSheet);
        $this->authorize('view', $costSheet);

        return view('tan90.brc.costing.show', compact('costSheet'));
    }

    public function rollup(Request $request, int $finishedGood)
    {
        $finishedGood = FinishedGood::findOrFail($finishedGood);
        $this->authorize('create', CostSheet::class);

        $data = $request->validate([
            'tan90_bom_id' => 'required|exists:tan90_boms,id',
            'cost_period' => 'required|string',
        ]);

        $bom = Bom::findOrFail($data['tan90_bom_id']);
        $bomVersion = $bom->currentVersion;
        abort_if(! $bomVersion, 422, 'This BOM has no current revision to roll up.');

        $rollup = $this->costRollup->rollUp($finishedGood, $bomVersion, $data['cost_period']);

        $costSheet = CostSheet::firstOrCreate(
            ['tan90_finished_good_id' => $finishedGood->id, 'cost_period' => $data['cost_period']],
            ['code' => 'CST-' . $finishedGood->code . '-' . $data['cost_period'], 'status' => 'active', 'approval_status' => 'draft']
        );

        return redirect()->route('tan90.brc.costing.show', $costSheet->id)
            ->with('status', "Cost roll-up complete: total {$rollup->total_cost}.");
    }

    public function approveStandard(int $costSheet)
    {
        $costSheet = CostSheet::findOrFail($costSheet);
        $this->authorize('approve', $costSheet);

        $result = $this->standardCost->approve($costSheet);
        if (! $result['approved']) {
            return back()->withErrors(['approval' => implode(' ', $result['errors'])]);
        }

        return back()->with('status', 'Standard cost approved.');
    }

    public function recordActual(Request $request, int $costSheet)
    {
        $costSheet = CostSheet::findOrFail($costSheet);
        $this->authorize('update', $costSheet);

        $data = $request->validate([
            'material' => 'nullable|numeric',
            'labor' => 'nullable|numeric',
            'machine' => 'nullable|numeric',
            'utility' => 'nullable|numeric',
            'overhead' => 'nullable|numeric',
        ]);

        $this->actualCost->recordActual($costSheet, array_filter($data, fn ($v) => $v !== null));

        return back()->with('status', 'Actual cost recorded.');
    }

    public function simulate(Request $request, int $costSheet)
    {
        $costSheet = CostSheet::findOrFail($costSheet);
        $this->authorize('view', $costSheet);

        $data = $request->validate([
            'scenario_name' => 'required|string',
            'adjustments' => 'required|array',
            'adjustments.*' => 'numeric',
            'selling_price' => 'nullable|numeric',
        ]);

        $simulation = $this->costSimulation->simulate(
            $costSheet->finishedGood,
            $costSheet,
            $data['scenario_name'],
            $data['adjustments'],
            $data['selling_price'] ?? null
        );

        return back()->with('status', "Simulation '{$simulation->scenario_name}' saved: {$simulation->simulated_total_cost}.");
    }
}
