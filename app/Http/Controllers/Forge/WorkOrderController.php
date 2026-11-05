<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\JobCard;
use App\Models\Forge\ProductionEntry;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\ProductionFgMapping;
use App\Models\Forge\WorkOrder;
use App\Models\Forge\Machine;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\Tan90\MasterData\Bin;
use App\Services\Access\AccessControlService;
use App\Services\Inventory\InventoryService;
use App\Services\Forge\WorkOrderService;
use App\Services\Forge\SalesProductionService;
use App\Services\Forge\ProductionTaskService;
use App\Services\Forge\WorkOrderListing;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkOrderController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private WorkOrderService $service,
        private WorkOrderListing $listing,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.view'), 403);
        $site = SiteLocation::forUser($request->user());
        $phase = $this->listing->activePhase($request);

        return view($request->routeIs('forge.workorders.create') ? 'forge.work-orders.create' : 'forge.work-orders.index', [
            'workOrders' => $this->listing->query($request)->latest()->paginate(20)->withQueryString(),
            'plans' => ProductionPlan::where('status', 'frozen')->with('finishedGood')->withSum('workOrders', 'target_qty')
                ->when($site !== null, fn ($q) => $q->where('site_id', $site))
                ->orderByDesc('id')->get(),
            'inventoryFgItems' => InventoryItem::where('item_type', 'fg')->where('active', true)->orderBy('code')->get(),
            'reusablePcmItems' => InventoryItem::where('item_type', 'rm')->where('active', true)->orderBy('code')->get(),
            'locations' => SiteLocation::visibleLocations(),
            'warehouses' => Warehouse::where('status', 'active')->whereIn('warehouse_type', ['Raw Material', 'Finished Goods'])->when($site !== null, fn ($q) => $q->where('tan90_location_id', $site))->orderBy('code')->get(),
            'approvedBoms' => Bom::where('status', 'active')->where('approval_status', 'approved')->orderBy('code')->get(),
            'approvedRecipes' => Recipe::where('status', 'active')->where('approval_status', 'approved')->orderBy('code')->get(),
            'mappingOptions' => ProductionFgMapping::where('status', 'approved')
                ->get(['site_id', 'finished_good_id', 'inventory_fg_item_id', 'rm_warehouse_id', 'fg_warehouse_id', 'reusable_pcm_item_id'])->toArray(),
            'canCreateWorkOrder' => $this->access->can($request->user(), 'forge.workorder.create'),
            'canExportWorkOrders' => $this->access->can($request->user(), 'forge.workorder.export'),
            'phaseLabel' => $phase['label'] ?? null,
            'statuses' => WorkOrder::STATUSES,
            'filters' => $request->only(['phase', 'status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.create'), 403);

        return $this->index($request);
    }

    public function show(Request $request, WorkOrder $workOrder, ?string $moduleStep = null)
    {
        abort_unless($this->access->can($request->user(), $moduleStep === 'quality' ? 'forge.finalqc.record'
            : ($moduleStep === 'finished-goods' ? 'flow.inventory.view' : 'forge.workorder.view')), 403);
        $this->assertSite($request, $workOrder);

        $workOrder->load([
            'finishedGood', 'inventoryFgItem', 'reusablePcmItem', 'bom', 'recipe', 'routing.operations', 'productionPlan.sourceOrderLine.order',
            'jobCards.machine', 'jobCards.operator',
            'materialIssues', 'productionEntries', 'wastageRecords', 'qualityHolds', 'deviations', 'batch',
            'batch.inventoryLot', 'finalQcResult',
        ]);

        $bomItems = $workOrder->inventory_fg_item_id
            ? InventoryItem::find($workOrder->inventory_fg_item_id)?->bomLines()->with('component')->get() ?? collect()
            : collect();
        $materialStock = [];
        if ($moduleStep === 'materials' && $workOrder->rm_warehouse_id && $bomItems->isNotEmpty()) {
            $materialStock = app(InventoryService::class)->atWarehouse((int) $workOrder->rm_warehouse_id)
                ->balances($bomItems->pluck('component')->filter()) ?: [];
        }

        return view($moduleStep ? 'forge.work-orders.module-work-order' : 'forge.work-orders.show', [
            'wo' => $workOrder,
            'moduleStep' => $moduleStep,
            'machines' => Machine::where('site_id', $workOrder->site_id)->orderBy('name')->get(),
            'reservations' => \Illuminate\Support\Facades\DB::table('forge_material_reservations')
                ->join('inventory_items', 'inventory_items.id', '=', 'forge_material_reservations.inventory_item_id')
                ->where('forge_material_reservations.work_order_id', $workOrder->id)
                ->get(['inventory_items.code', 'inventory_items.name', 'forge_material_reservations.qty', 'inventory_items.unit']),
            'mixingRecords' => \Illuminate\Support\Facades\DB::table('forge_mixing_records')->where('work_order_id', $workOrder->id)->latest()->get(),
            'processRecords' => \Illuminate\Support\Facades\DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->latest()->get(),
            'qcRecords' => \Illuminate\Support\Facades\DB::table('forge_final_qc_results')->where('work_order_id', $workOrder->id)->orderBy('id')->get(),
            'recoveredPcmAvailable' => max(0, (float) \Illuminate\Support\Facades\DB::table('forge_final_qc_results')->where('work_order_id', $workOrder->id)->whereNotNull('released_at')->sum('recovered_pcm_qty')
                + (float) \Illuminate\Support\Facades\DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->sum('recovered_pcm_qty')
                - (float) \Illuminate\Support\Facades\DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->sum('recovered_pcm_used_qty')),
            'bomItems' => $bomItems,
            'materialStock' => $materialStock,
            'eligibleFgBins' => $moduleStep === 'finished-goods' && $workOrder->fg_warehouse_id
                ? Bin::with('shelf.rack.zone')->where('status', 'active')->where('approval_status', 'approved')->get()
                    ->filter(fn ($bin) => (int) $bin->shelf?->rack?->zone?->tan90_warehouse_id === (int) $workOrder->fg_warehouse_id)
                : collect(),
            'materialRequests' => \Illuminate\Support\Facades\DB::table('forge_material_requests')->where('work_order_id', $workOrder->id)->latest()->get(),
            'requestLines' => \Illuminate\Support\Facades\DB::table('forge_material_request_lines as l')->join('forge_material_requests as r', 'r.id', '=', 'l.material_request_id')
                ->join('inventory_items as i', 'i.id', '=', 'l.inventory_item_id')->where('r.work_order_id', $workOrder->id)
                ->get(['l.material_request_id', 'l.qty', 'l.extra_reason', 'i.code', 'i.name', 'i.unit']),
            'bundleMaterials' => \Illuminate\Support\Facades\DB::table('forge_material_issues as m')->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
                ->where('m.work_order_id', $workOrder->id)->where('m.movement_type', 'issue')
                ->groupBy('m.inventory_item_id', 'i.code', 'i.name', 'i.unit')
                ->get(['m.inventory_item_id', 'i.code', 'i.name', 'i.unit', \Illuminate\Support\Facades\DB::raw('SUM(m.qty) as issued_qty')]),
            'consumptionRecord' => \Illuminate\Support\Facades\DB::table('forge_bundle_records')->where('work_order_id', $workOrder->id)->latest('id')->first(),
            'consumptionLines' => \Illuminate\Support\Facades\DB::table('forge_bundle_material_lines as l')->join('forge_bundle_records as r', 'r.id', '=', 'l.bundle_record_id')
                ->join('inventory_items as i', 'i.id', '=', 'l.inventory_item_id')->where('r.work_order_id', $workOrder->id)
                ->orderBy('l.id')->get(['l.inventory_item_id', 'i.code', 'i.name', 'i.unit', 'l.used_qty', 'l.scrap_qty', 'l.return_qty']),
            'canViewSalesOrder' => $this->access->can($request->user(), 'flow.order.view'),
            'canReleaseQc' => $this->access->can($request->user(), 'forge.finalqc.release'),
            'canReceiveFg' => $this->access->can($request->user(), 'flow.inventory.receive'),
            'canPutawayFg' => $this->access->can($request->user(), 'flow.inventory.putaway'),
            'canCloseWorkOrder' => $this->access->can($request->user(), 'forge.workorder.release'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.create'), 403);

        $data = $request->validate([
            'production_plan_id' => ['required', 'exists:forge_production_plans,id'],
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'bom_id' => ['nullable', 'exists:tan90_boms,id'],
            'recipe_id' => ['nullable', 'exists:tan90_recipes,id'],
            'routing_id' => ['nullable', 'exists:tan90_routings,id'],
            'plant' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'target_qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
            'inventory_fg_item_id' => ['required', 'exists:inventory_items,id'],
            'reusable_pcm_item_id' => ['nullable', 'exists:inventory_items,id'],
            'site_id' => ['required', 'exists:tan90_locations,id'],
            'rm_warehouse_id' => ['required', 'exists:tan90_warehouses,id'],
            'fg_warehouse_id' => ['required', 'exists:tan90_warehouses,id'],
        ]);
        $plan = ProductionPlan::where('status', 'frozen')->findOrFail($data['production_plan_id']);
        if ((int) $plan->finished_good_id !== (int) $data['finished_good_id']) {
            throw ValidationException::withMessages(['finished_good_id' => 'Choose the finished good linked to this production plan.']);
        }
        if (! $plan->site_id || ! $plan->finishedGood
            || strcasecmp(trim((string) $plan->uom), trim((string) $plan->finishedGood->uom)) !== 0) {
            throw ValidationException::withMessages(['production_plan_id' => 'Repair this plan’s site or unit in Production Plans before creating another work order.']);
        }
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && $site !== (int) $data['site_id'], 403);
        abort_if($site !== null && ! $plan->site_id, 403);
        if ($plan->site_id !== null && (int) $plan->site_id !== (int) $data['site_id']) {
            throw ValidationException::withMessages(['site_id' => 'Choose the same production site shown on the selected plan.']);
        }
        $definition = ! empty($data['recipe_id']) ? Recipe::find($data['recipe_id']) : Bom::find($data['bom_id'] ?? 0);
        if (! $definition || $definition->status !== 'active' || $definition->approval_status !== 'approved'
            || (int) $definition->tan90_finished_good_id !== (int) $data['finished_good_id']) {
            throw ValidationException::withMessages([
                ! empty($data['recipe_id']) ? 'recipe_id' : 'bom_id' => 'Choose an active, approved recipe or BOM for the selected finished good.',
            ]);
        }
        if (! InventoryItem::whereKey($data['inventory_fg_item_id'])->where('item_type', 'fg')->where('active', true)->exists()) {
            throw ValidationException::withMessages(['inventory_fg_item_id' => 'Choose an active inventory item classified as a finished good.']);
        }
        foreach (['rm_warehouse_id' => ['RM/PM', 'Raw Material'], 'fg_warehouse_id' => ['FG', 'Finished Goods']] as $key => [$label, $type]) {
            if (! Warehouse::whereKey($data[$key])->where('tan90_location_id', $data['site_id'])->where('status', 'active')->where('warehouse_type', $type)->exists()) {
                throw ValidationException::withMessages([$key => "Choose an active {$type} warehouse assigned to the selected production site."]);
            }
        }
        if (! empty($data['reusable_pcm_item_id'])) {
            if (! InventoryItem::whereKey($data['reusable_pcm_item_id'])->where('item_type', 'rm')->where('active', true)->exists()) {
                throw ValidationException::withMessages(['reusable_pcm_item_id' => 'Choose an active raw-material SKU for recovered PCM, or leave this optional field blank.']);
            }
        }
        $mapping = app(SalesProductionService::class)->approvedMapping((int) $data['site_id'], (int) $data['finished_good_id'], (int) $data['fg_warehouse_id']);
        foreach (['inventory_fg_item_id', 'rm_warehouse_id', 'fg_warehouse_id'] as $key) {
            if ((int) $data[$key] !== (int) $mapping->$key) {
                throw ValidationException::withMessages([$key => 'Use the SKU and warehouse from the approved product and site mapping.']);
            }
        }
        if ((int) ($data['reusable_pcm_item_id'] ?? 0) !== (int) ($mapping->reusable_pcm_item_id ?? 0)) {
            throw ValidationException::withMessages(['reusable_pcm_item_id' => 'Use the PCM reference from the approved product and site mapping, or leave it blank when none is mapped.']);
        }
        if ($data['uom'] !== $plan->uom) {
            throw ValidationException::withMessages(['uom' => 'Use the unit of measure shown on the selected production plan.']);
        }

        $wo = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request): WorkOrder {
            $lockedPlan = ProductionPlan::whereKey($data['production_plan_id'])->where('status', 'frozen')->lockForUpdate()->firstOrFail();
            $already = (float) WorkOrder::where('production_plan_id', $lockedPlan->id)->sum('target_qty');
            if ($already + (float) $data['target_qty'] > (float) $lockedPlan->target_qty + 0.0005) {
                throw ValidationException::withMessages(['target_qty' => 'Reduce this quantity: existing work orders plus this quantity cannot exceed the linked plan target.']);
            }

            return WorkOrder::create($data + [
                'wo_number' => $lockedPlan->source_order_line_id ? 'WO-SO-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::ulid())
                    : 'WO-M-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::ulid()),
                'status' => 'draft',
                'workflow_version' => 2,
                'created_by' => $request->user()->id,
            ]);
        });
        if ((int) $wo->workflow_version >= 2) {
            app(ProductionTaskService::class)->done('plan:'.$plan->id.':workorder', $request->user()->id);
            app(ProductionTaskService::class)->open('wo:'.$wo->id.':release', 'Release work order '.$wo->wo_number,
                'forge.workorder.release', (int) $wo->site_id, $request->user()->id, $wo);
        }

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

        return redirect()->route('forge.modules.workorder', ['step' => 'materials', 'workOrder' => $wo])
            ->with('status', "Work order {$wo->wo_number} created. Review its recipe and issue RM/PM.");
    }

    public function release(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.release'), 403);
        $this->assertSite($request, $workOrder);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request,$workOrder) {
            $wo=WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            if($wo->status!=='draft') return;
            if((int)$wo->workflow_version>=3 && $wo->productionPlan?->status==='draft') {
                $wo->productionPlan->update(['status'=>'frozen','approved_by'=>$request->user()->id,'approved_at'=>now()]);
            }
            $this->service->release($wo, $request->user());
        });

        return redirect()->route('forge.modules.show',['step'=>'materials'])->with('status', "Work order {$workOrder->wo_number} released for RM & PM issue.");
    }

    public function reserveMaterial(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.material.issue'), 403);
        $this->assertSite($request, $workOrder);
        $this->service->reserveMaterial($workOrder);

        return back()->with('status', "Material reserved for {$workOrder->wo_number}.");
    }

    public function issueMaterial(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.material.issue'), 403);
        $this->assertSite($request, $workOrder);

        $handover = $workOrder->inventory_fg_item_id ? $request->validate([
            'handover_started_at' => ['required', 'date'],
            'handover_ended_at' => ['required', 'date', 'after:handover_started_at'],
            'manpower' => ['required', 'integer', 'min:1'],
        ]) : [];

        $data = $workOrder->inventory_fg_item_id ? ['lines' => []] : $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_code' => ['required', 'string', 'max:100'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.uom' => ['required', 'string', 'max:20'],
        ]);

        $this->service->issueMaterial($workOrder, $data['lines'], $request->user(), $handover);

        return back()->with('status', "Material issued to {$workOrder->wo_number}.");
    }

    public function start(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->assertSite($request, $workOrder);
        $this->service->startProgress($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} started.");
    }

    public function resumeRework(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.record'), 403);
        $this->assertSite($request, $workOrder);
        $this->service->resumeRework($workOrder, $request->user());

        return back()->with('status', "Same work order {$workOrder->wo_number} resumed for rework and another QC round.");
    }

    public function startJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->assertSite($request, $jobCard->workOrder);
        $data = $request->validate(['machine_id' => ['nullable', 'integer', 'exists:forge_machines,id']]);
        $machineSelectionProvided = array_key_exists('machine_id', $data);
        $machineId = $machineSelectionProvided ? $data['machine_id'] : $jobCard->machine_id;
        if ($machineId) {
            $machine = Machine::findOrFail($machineId);
            if ($machine->site_id === null || (int) $machine->site_id !== (int) $jobCard->workOrder->site_id) {
                throw ValidationException::withMessages(['machine_id' => 'Choose a machine assigned to this work order site.']);
            }
        }
        $this->service->startJobCard($jobCard, $machineId, $request->user(), $machineSelectionProvided);

        return back()->with('status', "Job card '{$jobCard->operation_name}' started.");
    }

    public function pauseJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->assertSite($request, $jobCard->workOrder);
        $this->service->pauseJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' paused.");
    }

    public function resumeJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->assertSite($request, $jobCard->workOrder);
        if ($jobCard->machine_id) {
            $machine = Machine::findOrFail($jobCard->machine_id);
            if ($machine->site_id === null || (int) $machine->site_id !== (int) $jobCard->workOrder->site_id) {
                throw ValidationException::withMessages(['machine_id' => 'Choose a machine assigned to this work order site.']);
            }
        }
        $this->service->resumeJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' resumed.");
    }

    public function completeJobCard(Request $request, JobCard $jobCard)
    {
        abort_unless($this->access->can($request->user(), 'forge.jobcard.record'), 403);
        $this->assertSite($request, $jobCard->workOrder);
        $this->service->completeJobCard($jobCard);

        return back()->with('status', "Job card '{$jobCard->operation_name}' completed.");
    }

    public function recordProduction(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.record'), 403);
        $this->assertSite($request, $workOrder);

        $data = $request->validate([
            'job_card_id' => ['nullable', Rule::exists('forge_job_cards', 'id')->where(fn ($query) => $query->where('work_order_id', $workOrder->id))],
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
        $this->assertSite($request, $entry->workOrder);
        $this->service->approveProduction($entry, $request->user());

        return back()->with('status', 'Production entry approved and posted.');
    }

    public function sendToFinalQc(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.production.approve'), 403);
        $this->assertSite($request, $workOrder);
        $this->service->sendToFinalQc($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} sent to final QC.");
    }

    public function close(Request $request, WorkOrder $workOrder)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.release'), 403);
        $this->assertSite($request, $workOrder);
        $this->service->close($workOrder);

        return back()->with('status', "Work order {$workOrder->wo_number} closed.");
    }

    private function assertSite(Request $request, WorkOrder $workOrder): void
    {
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
    }
}
