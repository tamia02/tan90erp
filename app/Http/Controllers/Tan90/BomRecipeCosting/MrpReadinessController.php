<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Services\Tan90\BomRecipeCosting\MrpReadinessService;

class MrpReadinessController extends Controller
{
    public function __construct(private readonly MrpReadinessService $mrpReadiness)
    {
    }

    public function index()
    {
        $this->authorize('viewAny', FinishedGood::class);

        $finishedGoods = FinishedGood::where('status', 'active')->orderBy('name')->get();
        $readiness = $finishedGoods->mapWithKeys(fn ($fg) => [$fg->id => $this->mrpReadiness->check($fg)]);

        return view('tan90.brc.mrp-readiness.index', compact('finishedGoods', 'readiness'));
    }

    public function show(int $finishedGood)
    {
        $finishedGood = FinishedGood::findOrFail($finishedGood);
        $this->authorize('view', $finishedGood);

        $result = $this->mrpReadiness->check($finishedGood);

        return view('tan90.brc.mrp-readiness.show', compact('finishedGood', 'result'));
    }
}
