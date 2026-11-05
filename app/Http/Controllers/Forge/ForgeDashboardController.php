<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Deviation;
use App\Models\Forge\Machine;
use App\Models\Forge\MachineDowntimeEvent;
use App\Models\Forge\QualityHold;
use App\Models\Forge\WorkOrder;
use App\Models\Forge\WastageRecord;
use App\Services\Access\AccessControlService;
use App\Services\Forge\MachineRuntimeService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ForgeDashboardController extends Controller
{
    public function __construct(private AccessControlService $access, private MachineRuntimeService $runtime) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.dashboard.view'), 403);

        $site = SiteLocation::forUser($request->user());
        $workOrderQuery = WorkOrder::query()->when($site !== null, fn ($query) => $query->where('site_id', $site));
        $workOrders = (clone $workOrderQuery)->with('finishedGood')->latest()->limit(500)->get();
        $wastageToday = WastageRecord::query()
            ->whereDate('created_at', now()->toDateString())
            ->whereHas('workOrder', fn ($query) => $query->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))
            ->get(['qty', 'uom']);
        $wastageByUnit = $wastageToday->groupBy(fn (WastageRecord $record) => $record->uom)
            ->map(fn ($records, $unit) => number_format((float) $records->sum('qty'), 3).' '.$unit);
        $canViewMachineTime = $this->access->can($request->user(), 'forge.machine.view');
        $machineTimes = collect();
        if ($canViewMachineTime) {
            $now = now();
            $today = $now->copy()->startOfDay();
            $runs = DB::table('forge_machine_run_intervals')
                ->where('started_at', '<', $now)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $today))
                ->when($site !== null, fn ($query) => $query->whereIn('machine_id', Machine::where('site_id', $site)->select('id')))
                ->get(['machine_id', 'started_at', 'ended_at'])->groupBy('machine_id');
            $visibleMachineIds = Machine::query()->when($site !== null, fn ($query) => $query->where('site_id', $site))->pluck('id');
            $stops = MachineDowntimeEvent::query()
                ->where('started_at', '<', $now)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $today))
                ->when($site !== null, fn ($query) => $query->whereIn('machine_id', $visibleMachineIds))
                ->get(['machine_id', 'started_at', 'ended_at'])->groupBy('machine_id');
            $machineTimes = Machine::query()->when($site !== null, fn ($query) => $query->where('site_id', $site))->orderBy('name')->get()->map(function (Machine $machine) use ($runs, $stops, $today, $now) {
                return [
                    'machine' => $machine,
                    'time' => $this->runtime->summarize(
                        $runs->get($machine->id, collect()),
                        $stops->get($machine->id, collect()),
                        $today,
                        $now,
                    ),
                ];
            });
        }

        return view('forge.dashboard', [
            'statusCounts' => $workOrders->countBy('status'),
            'openHolds' => QualityHold::where('status', 'open')->whereHas('workOrder', fn ($query) => $query->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))->count(),
            'openDowntime' => MachineDowntimeEvent::whereNull('ended_at')->when($site !== null, fn ($query) => $query->whereHas('machine', fn ($machine) => $machine->where('site_id', $site)))->count(),
            'openDeviations' => Deviation::whereNotIn('status', ['closed'])
                ->when($site !== null, fn ($query) => $query->whereHas('workOrder', fn ($workOrder) => $workOrder->where('site_id', $site)))
                ->count(),
            'inProgress' => $workOrders->whereIn('status', ['in_progress', 'reconciliation'])->take(10),
            'finalQcPending' => $workOrders->where('status', 'final_qc_pending')->take(10),
            'machineTimes' => $machineTimes,
            'canViewMachineTime' => $canViewMachineTime,
            'machineTotals' => [
                'scheduled_seconds' => $machineTimes->sum(fn (array $row) => $row['time']['scheduled_seconds']),
                'stopped_seconds' => $machineTimes->sum(fn (array $row) => $row['time']['stopped_seconds']),
                'working_seconds' => $machineTimes->sum(fn (array $row) => $row['time']['working_seconds']),
            ],
            'wastageTodayCount' => $wastageToday->count(),
            'wastageTodayByUnit' => $wastageByUnit,
        ]);
    }
}
