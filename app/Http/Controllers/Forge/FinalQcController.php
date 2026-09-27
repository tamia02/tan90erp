<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\FinalQcResult;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\Forge\WorkOrderService;
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

        return view('forge.final-qc.index', [
            'pending' => WorkOrder::where('status', 'final_qc_pending')->with('finishedGood')->get(),
            'results' => FinalQcResult::with('workOrder.finishedGood')->latest()->paginate(20),
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.finalqc.record'), 403);

        $data = $request->validate([
            'accepted_qty' => ['required', 'numeric', 'min:0'],
            'rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'rework_qty' => ['nullable', 'numeric', 'min:0'],
            'specification_results' => ['nullable', 'string', 'max:4000'],
            'result' => ['required', 'in:released,rework,rejected'],
        ]);

        $this->service->recordFinalQc($workOrder, $data, $request->user());

        return redirect()->route('forge.final-qc.index')->with('status', 'Final QC recorded.');
    }

    public function release(Request $request, FinalQcResult $finalQcResult)
    {
        abort_unless($this->access->can($request->user(), 'forge.finalqc.release'), 403);

        $wo = $this->service->releaseFinalQc($finalQcResult, $request->user());

        return back()->with('status', "Work order {$wo->wo_number} — {$wo->status}.");
    }
}
