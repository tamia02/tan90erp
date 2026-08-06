<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\JobCard;
use App\Models\Forge\ProductionEntry;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WorkOrder;
use App\Models\Forge\Machine;
use App\Services\Access\AccessControlService;
use App\Services\Forge\WorkOrderService;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private WorkOrderService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.view'), 403);

        return view('forge.work-orders.index', [
            'workOrders' => WorkOrder::with('finishedGood')->latest()->paginate(20),
            'plans' => ProductionPlan::where('status', 'frozen')->with('finishedGood')->orderByDesc('id')->get(),
        ]);
    }

    public function show(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.view'), 403);

        $workOrder->load([
            'finishedGood', 'bom', 'recipe', 'routing.operations', 'jobCards.machine', 'jobCards.operator',
            'materialIssues', 'productionEntries', 'wastageRecords', 'qualityHolds', 'deviations', 'batch',
        ]);

        return view('forge.work-orders.show', [
            'wo' => $workOrder,
            'machines' => Machine::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.create'), 403);

        $data = $request->validate([
            'production_plan_id' => ['nullable', 'exists:forge_production_plans,id'],
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'bom_id' => ['nullable', 'exists:tan90_boms,id'],
            'recipe_id' => ['nullable', 'exists:tan90_recipes,id'],
            'routing_id' => ['nullable', 'exists:tan90_routings,id'],
            'plant' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'target_qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
        ]);

        $wo = WorkOrder::create($data + [
            'wo_number' => 'WO-'.now()->format('Y').'-'.str_pad((string) (WorkOrder::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        if ($wo->routing_id && $wo->jobCards()->count() === 0) {
            $wo->load('routing.operations');
            foreach ($wo->routing->operations as $operation) {
                JobCard::create([
                    'work_order_id' => $wo->id,
                    'routing_operation_id' => $operation->id,
                    'sequence' => $operation->sequence,
                    'operation_name' => $operation->operation_name,
                    'planned_qty' => $wo->target_qty,
                    'status' => 'pending',
                ]);
            }
        }

        return redirect()->route('forge.workorders.show', $wo)->with('status', "Work order {$wo->wo_number} created.");
    }

    public function release(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.release'), 403);
        $this->service->release($workOrder, $request->user());

        return back()->with('status', "Work order {$workOrder->wo_number} released.");
    }

    public function reserveMaterial(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.material.issue'), 403);
        $this->service->reserveMaterial($workOrder);

        return back()->with('status', "Material reserved for {$workOrder->wo_number}.");
    }

    public function issueMaterial(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.material.issue'), 403);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_code' => ['required', 'string', 'max:100'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.uom' => ['required', 'string', 'max:20'],
        ]);

        $this->service->issueMaterial($workOrder, $data['lines'], $request->user());

        return back()->with('status', "Material issued to {$workOrder->wo_number}.");
    }

    public function start(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->service->startProgress($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} started.");
    }

    public function startJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $jobCard->update(['operator_user_id' => $jobCard->operator_user_id ?? $request->user()->id]);
        $this->service->startJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' started.");
    }

    public function pauseJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->service->pauseJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' paused.");
    }

    public function resumeJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->service->resumeJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' resumed.");
    }

    public function completeJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->service->completeJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' completed.");
    }

    public function recordProduction(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.record'), 403);

        $data = $request->validate([
            'job_card_id' => ['nullable', 'exists:forge_job_cards,id'],
            'good_qty' => ['required', 'numeric', 'min:0'],
            'rework_qty' => ['nullable', 'numeric', 'min:0'],
            'rejected_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->recordProduction($workOrder, $data, $request->user());

        return back()->with('status', 'Production entry recorded (pending approval).');
    }

    public function approveProduction(Request $request, ProductionEntry $entry)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.approve'), 403);
        $this->service->approveProduction($entry, $request->user());

        return back()->with('status', 'Production entry approved and posted.');
    }

    public function sendToFinalQc(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.approve'), 403);
        $this->service->sendToFinalQc($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} sent to final QC.");
    }

    public function close(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.release'), 403);
        $this->service->close($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} closed.");
    }
}
