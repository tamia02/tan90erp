<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\HandlingUnit;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class PackingController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.pack.manage'), 403);

        return view('flow.packing.index', [
            'handlingUnits' => HandlingUnit::with('order')->latest()->paginate(20),
            'pickedOrders' => CustomerOrder::whereIn('status', ['picking', 'picked', 'packing'])->get(),
        ]);
    }

    public function store(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.pack.manage'), 403);

        $data = $request->validate([
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'qty_packed' => ['required', 'numeric', 'min:0.001'],
        ]);

        $hu = $this->service->packIntoHandlingUnit($order, $data, $request->user());

        return back()->with('status', "Handling unit {$hu->hu_number} packed.");
    }

    public function seal(Request $request, HandlingUnit $handlingUnit)
    {
        abort_unless($this->access->can($request->user(), 'flow.pack.manage'), 403);
        $this->service->sealHandlingUnit($handlingUnit);

        return back()->with('status', "Handling unit {$handlingUnit->hu_number} sealed.");
    }
}
