<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\ReturnRequest;
use App\Models\Flow\Shipment;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class FlowDashboardController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.dashboard.view'), 403);

        $orders = CustomerOrder::latest()->limit(500)->get();

        return view('flow.dashboard', [
            'statusCounts' => $orders->countBy('status'),
            'openReturns' => ReturnRequest::whereNotIn('status', ['closed'])->count(),
            'inTransitShipments' => Shipment::where('status', 'in_transit')->count(),
            'awaitingAllocation' => $orders->whereIn('status', ['released', 'atp_partial'])->take(10),
            'inTransit' => $orders->where('status', 'in_transit')->take(10),
        ]);
    }
}
