<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\FinalQcResult;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\Forge\WorkOrderService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class FinalQcController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private WorkOrderService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.finalqc.record'), 403);
        $workOrderId = $request->query('work_order');
        $workOrderId = ctype_digit((string) $workOrderId) ? (int) $workOrderId : null;

        return view('forge.final-qc.index', [
            'pending' => WorkOrder::where('status', 'final_qc_pending')->with('finishedGood')
                ->when($workOrderId, fn ($q) => $q->whereKey($workOrderId))
                ->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->where('site_id', $site))->get(),
            'results' => FinalQcResult::with('workOrder.finishedGood')
                ->when($workOrderId, fn ($q) => $q->where('work_order_id', $workOrderId))
                ->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->whereHas('workOrder', fn ($wo) => $wo->where('site_id', $site)))
                ->latest()->paginate(20)->withQueryString(),
            'focusedWorkOrderId' => $workOrderId,
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.finalqc.record'), 403);
        $this->assertSite($request, $workOrder);

        $data = $request->validate([
            'started_at' => [$workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'date'],
            'ended_at' => [$workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'date', 'after_or_equal:started_at'],
            'manpower' => [$workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'integer', 'min:1'],
            'accepted_qty' => ['required', 'numeric', 'min:0'],
            'rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'rework_qty' => ['nullable', 'numeric', 'min:0'],
            'specification_results' => [((int)$workOrder->workflow_version>=3 && ((float)$request->input('rejected_qty',0)>0 || (float)$request->input('rework_qty',0)>0))?'required':'nullable', 'string', 'max:4000'],
            'result' => ['required', 'in:released,rework,rejected'],
            'leak_test' => [$workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'in:pass,fail'],
            'pressure_test' => [$workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'in:pass,fail'],
            'physical_check' => [(int) $workOrder->workflow_version >= 2 && $workOrder->inventory_fg_item_id ? 'required' : 'nullable', 'in:pass,fail'],
            'recovered_pcm_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->recordFinalQc($workOrder, $data, $request->user());

        return redirect()->route('forge.modules.workorder', ['step' => 'quality', 'workOrder' => $workOrder])->with('status', 'Final QC recorded.');
    }

    public function release(Request $request, FinalQcResult $finalQcResult)
    {
        abort_unless($this->access->can($request->user(), 'forge.finalqc.release'), 403);
        $this->assertSite($request, $finalQcResult->workOrder);

        $wo = \Illuminate\Support\Facades\DB::transaction(function () use ($request,$finalQcResult) {
            $wo=WorkOrder::whereKey($finalQcResult->work_order_id)->lockForUpdate()->firstOrFail();
            $qc=FinalQcResult::whereKey($finalQcResult->id)->lockForUpdate()->firstOrFail();
            if($qc->released_at) return $wo;
            return $this->service->releaseFinalQc($qc,$request->user());
        });

        $next=match($wo->status){'rework'=>'PCM Making · Mixing Operator','bundle_pending'=>'Bundle · Production Worker','rejected'=>'Rejected run review · Production Manager',default=>'FG Store · FG Receiver'};
        return back()->with('status', 'QC decision approved. Next: '.$next.'.');
    }

    private function assertSite(Request $request, WorkOrder $workOrder): void
    {
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
    }
}
