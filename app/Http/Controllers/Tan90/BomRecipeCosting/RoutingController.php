<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\Routing;
use App\Models\Tan90\BomRecipeCosting\RoutingOperation;
use Illuminate\Http\Request;

class RoutingController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Routing::class);

        $routings = Routing::with('finishedGood')
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%' . $request->string('q') . '%'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('tan90.brc.routings.index', compact('routings'));
    }

    public function create()
    {
        $this->authorize('create', Routing::class);

        return view('tan90.brc.routings.form', ['routing' => new Routing]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Routing::class);

        $data = $request->validate([
            'code' => 'required|string|unique:tan90_routings,code',
            'tan90_finished_good_id' => 'required|exists:tan90_finished_goods,id',
            'name' => 'required|string',
        ]);
        $data['status'] = 'active';
        $data['approval_status'] = 'draft';

        $routing = Routing::create($data);

        return redirect()->route('tan90.brc.routings.show', $routing->id)->with('status', "Routing {$routing->code} created.");
    }

    public function show(int $routing)
    {
        $routing = Routing::with(['finishedGood', 'operations.workCenter', 'operations.processParameters'])->findOrFail($routing);
        $this->authorize('view', $routing);

        return view('tan90.brc.routings.show', compact('routing'));
    }

    public function storeOperation(Request $request, int $routing)
    {
        $routing = Routing::findOrFail($routing);
        $this->authorize('update', $routing);

        $data = $request->validate([
            'sequence' => 'nullable|integer',
            'operation_name' => 'required|string',
            'tan90_work_center_id' => 'required|exists:tan90_work_centers,id',
            'setup_time_minutes' => 'nullable|numeric',
            'run_time_minutes' => 'nullable|numeric',
        ]);
        $data['sequence'] ??= $routing->operations()->max('sequence') + 1;

        $routing->operations()->create($data);

        return back()->with('status', 'Routing operation added.');
    }

    public function destroyOperation(int $routing, int $operation)
    {
        $routing = Routing::findOrFail($routing);
        $this->authorize('update', $routing);

        $routing->operations()->whereKey($operation)->delete();

        return back()->with('status', 'Routing operation removed.');
    }

    public function storeProcessParameter(Request $request, int $operation)
    {
        $operation = RoutingOperation::findOrFail($operation);
        $this->authorize('update', $operation->routing);

        $data = $request->validate([
            'parameter_name' => 'required|string',
            'target_value' => 'nullable|string',
            'min_value' => 'nullable|string',
            'max_value' => 'nullable|string',
            'uom' => 'nullable|string',
            'criticality' => 'required|in:Critical,Major,Minor',
        ]);

        $operation->processParameters()->create($data);

        return back()->with('status', 'Process parameter added.');
    }
}
