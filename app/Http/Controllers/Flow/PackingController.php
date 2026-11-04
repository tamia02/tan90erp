<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\HandlingUnit;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use App\Support\SiteLocation;
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
            'pickedOrders' => CustomerOrder::with('lines.finishedGood')->whereIn('status', ['picking', 'picked', 'packing'])
                ->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->where('site_id', $site))->get(),
        ]);
    }

    public function store(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.pack.manage'), 403);

        SiteLocation::assertCanAccessSiteRecord($request->user(), $order->site_id === null ? null : (int) $order->site_id);
        $data = $request->validate([
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'qty_packed' => [$order->site_id ? 'nullable' : 'required', 'numeric', 'min:0.001'],
            'lines' => [$order->site_id ? 'required' : 'nullable', 'array'],
            'lines.*.order_line_id' => ['required_with:lines', 'exists:flow_order_lines,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $hu = $this->service->packIntoHandlingUnit($order, $data, $request->user());

        return back()->with('status', "Handling unit {$hu->hu_number} packed.");
    }

    public function seal(Request $request, HandlingUnit $handlingUnit)
    {
        abort_unless($this->access->can($request->user(), 'flow.pack.manage'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $handlingUnit->order->site_id === null ? null : (int) $handlingUnit->order->site_id);
        $this->service->sealHandlingUnit($handlingUnit);

        return back()->with('status', "Handling unit {$handlingUnit->hu_number} sealed.");
    }
}
