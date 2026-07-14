<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\EngineeringChangeOrder;
use App\Services\Tan90\BomRecipeCosting\EngineeringChangeService;
use Illuminate\Http\Request;

class EngineeringChangeController extends Controller
{
    public function __construct(private readonly EngineeringChangeService $engineeringChanges)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', EngineeringChangeOrder::class);

        $ecos = EngineeringChangeOrder::with(['requestedBy', 'approvedBy'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('requested_at')
            ->paginate(15)
            ->withQueryString();

        return view('tan90.brc.eco.index', compact('ecos'));
    }

    public function show(int $eco)
    {
        $eco = EngineeringChangeOrder::with(['requestedBy', 'approvedBy', 'changeImpacts'])->findOrFail($eco);
        $this->authorize('view', $eco);

        return view('tan90.brc.eco.show', ['eco' => $eco, 'object' => $eco->object()]);
    }

    public function approve(int $eco)
    {
        $eco = EngineeringChangeOrder::findOrFail($eco);
        $this->authorize('approve', $eco);

        $this->engineeringChanges->approve($eco);

        return back()->with('status', "ECO {$eco->code} approved.");
    }

    public function implement(int $eco)
    {
        $eco = EngineeringChangeOrder::findOrFail($eco);
        $this->authorize('approve', $eco);

        $this->engineeringChanges->implement($eco);

        return back()->with('status', "ECO {$eco->code} marked implemented.");
    }
}
