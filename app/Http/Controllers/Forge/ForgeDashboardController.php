<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Deviation;
use App\Models\Forge\MachineDowntimeEvent;
use App\Models\Forge\QualityHold;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class ForgeDashboardController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.dashboard.view'), 403);

        $workOrders = WorkOrder::with('finishedGood')->latest()->limit(500)->get();

        return view('forge.dashboard', [
            'statusCounts' => $workOrders->countBy('status'),
            'openHolds' => QualityHold::where('status', 'open')->count(),
            'openDowntime' => MachineDowntimeEvent::whereNull('ended_at')->count(),
            'openDeviations' => Deviation::whereNotIn('status', ['closed'])->count(),
            'inProgress' => $workOrders->whereIn('status', ['in_progress', 'reconciliation'])->take(10),
            'finalQcPending' => $workOrders->where('status', 'final_qc_pending')->take(10),
        ]);
    }
}
