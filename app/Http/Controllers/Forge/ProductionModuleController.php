<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionModuleController extends Controller
{
    private const MODULES = [
        'materials' => [
            'number' => '01', 'title' => 'Start · RM & PM Issue', 'owner' => 'Store Manager / Production Planner',
            'purpose' => 'Prepare the production job and hand the right raw and packaging materials to the operator.',
            'fields' => ['Finished good and required quantity', 'Approved BOM or recipe and exact FG SKU', 'Site and RM/PM warehouse', 'FIFO lots, handover start/end, and people involved'],
            'checks' => ['The plan, recipe, SKU, site, and warehouse must match.', 'Only available stock at this site can be reserved and issued.', 'Issue is recorded once in the inventory ledger.'],
            'outcome' => 'A traceable work order with the correct materials issued.',
            'workspace' => 'forge.workorders.index', 'workspace_params' => ['phase' => 'materials'], 'workspace_label' => 'Open material issue queue',
            'statuses' => ['draft', 'released', 'material_reserved', 'material_issued'], 'permission' => 'forge.workorder.view',
        ],
        'mixing' => [
            'number' => '02', 'title' => 'PCM Making · Manual + Mixing', 'owner' => 'Mixing Operator',
            'purpose' => 'Make one complete PCM solution batch, ready for a continuous feed to the production line.',
            'fields' => ['Mix start and finish time', 'Mixing method and people involved', 'Material quantities, UOM, and solution output', 'Fill-machine time and any loss'],
            'checks' => ['Record one complete mix for the work order.', 'Recovered mixed solution stays with this work order; it is not warehouse stock.', 'A pause is recorded as downtime, not as another mix.'],
            'outcome' => 'One measured PCM batch ready for filling and sealing.',
            'workspace' => 'forge.workorders.index', 'workspace_params' => ['phase' => 'mixing'], 'workspace_label' => 'Open mixing work queue',
            'statuses' => ['material_issued', 'in_progress'], 'permission' => 'forge.workorder.view',
        ],
        'filling-sealing' => [
            'number' => '03', 'title' => 'Production · Filling & Sealing', 'owner' => 'Production Operator',
            'purpose' => 'Run the prepared PCM continuously through filling and sealing and record machine performance.',
            'fields' => ['Manual or automatic machine mode', 'Filling and sealing start/end times and quantities', 'People, power, and production loss', 'Breakdown/idle duration and reason'],
            'checks' => ['Enter one continuous PCM feed for this work order.', 'Filling and sealing outputs are tracked separately.', 'Sealed quantity cannot exceed filled quantity.'],
            'outcome' => 'Approved production output with machine and downtime trace.',
            'workspace' => 'forge.workorders.index', 'workspace_params' => ['phase' => 'filling-sealing'], 'workspace_label' => 'Open filling & sealing queue',
            'statuses' => ['in_progress', 'reconciliation'], 'permission' => 'forge.workorder.view',
        ],
        'quality' => [
            'number' => '04', 'title' => 'QC · Pressure & Leak Test', 'owner' => 'QC / Quality Manager',
            'purpose' => 'Inspect the produced units before they can become saleable finished goods.',
            'fields' => ['Test start/end time and people involved', 'Pressure-test and leak-test results', 'Accepted and rejected quantities', 'Recovered PCM and rework details'],
            'checks' => ['QC quantities cannot exceed approved production output.', 'Both required tests must pass before release.', 'Recovered PCM stays attached to the same work order.'],
            'outcome' => 'A released QC result with accepted, rejected, and recovered quantities traced.',
            'workspace' => 'forge.final-qc.index', 'workspace_params' => [], 'workspace_label' => 'Open quality inspection queue',
            'statuses' => ['final_qc_pending', 'rework'], 'permission' => 'forge.finalqc.record',
        ],
        'consumption-fg' => [
            'number' => '05', 'title' => 'Bundle · Consumption & FG Records', 'owner' => 'Production / Store Manager',
            'purpose' => 'Count physical bundles and loose units, then reconcile issued materials against the approved Inventory BOM and post accepted finished goods once.',
            'fields' => ['Physical bundle count, units per bundle and loose units', 'Released QC accepted and defective quantities', 'RM/PM consumed from approved Inventory BOM and unused balance returned', 'Finished-good batch and stock receipt'],
            'checks' => ['The system blocks material usage above the issued quantity.', 'Rejected or rework units do not become saleable FG.', 'Automatic consumption and FG posting cannot be duplicated.'],
            'outcome' => 'An auditable consumption record and one QC-approved FG batch.',
            'workspace' => 'forge.workorders.index', 'workspace_params' => ['phase' => 'consumption-fg'], 'workspace_label' => 'Review consumption & FG records',
            'statuses' => ['bundle_pending', 'released_to_fg', 'closed'], 'permission' => 'forge.workorder.view',
        ],
        'finished-goods' => [
            'number' => '06', 'title' => 'FG Store · Stored Bins', 'owner' => 'Finished Goods Store Manager',
            'purpose' => 'Put away released FG in the right warehouse bin and make it available for the linked order.',
            'fields' => ['QC-released FG batch and mapped warehouse', 'Active master bin suggested for the product', 'Putaway quantity, time, and people involved', 'Sales-order allocation and dispatch readiness'],
            'checks' => ['Finished good, site, and warehouse must match.', 'Putaway allocates existing stock; it does not post the FG ledger twice.', 'Shipment release deducts stock once and creates a Finance review task.'],
            'outcome' => 'A bin-located FG batch allocated to its order and ready for dispatch.',
            'workspace' => 'flow.inventory.index', 'workspace_params' => [], 'workspace_label' => 'Open finished goods inventory',
            'statuses' => ['released_to_fg', 'closed'], 'permission' => 'flow.inventory.view',
        ],
    ];

    public function __construct(private AccessControlService $access) {}

    public function show(Request $request, string $step): View
    {
        $module = self::MODULES[$step] ?? abort(404);
        abort_unless($this->access->can($request->user(), $module['permission']), 403);

        $site = SiteLocation::forUser($request->user());
        $workOrders = WorkOrder::query()
            ->with('finishedGood')
            ->whereIn('status', $module['statuses'])
            ->when($site !== null, fn ($query) => $query->where('site_id', $site))
            ->latest('id')
            ->limit(20)
            ->get();
        $recentCompleted = WorkOrder::query()->with('finishedGood')
            ->where('status', 'closed')->where('workflow_version', '>=', 2)
            ->when($site !== null, fn ($query) => $query->where('site_id', $site))
            ->latest('id')->first();

        return view('forge.modules.show', [
            'module' => $module,
            'step' => $step,
            'modules' => collect(self::MODULES)
                ->filter(fn (array $item) => $this->access->can($request->user(), $item['permission']))
                ->map(fn (array $item, string $key) => [
                'key' => $key,
                'number' => $item['number'],
                'title' => $item['title'],
                ])->values(),
            'workOrders' => $workOrders,
            'recentCompleted' => $recentCompleted,
            'workspaceUrl' => route($module['workspace'], $module['workspace_params']),
            'canViewPlans' => $this->access->can($request->user(), 'forge.plan.view'),
            'canCreateWorkOrder' => $this->access->can($request->user(), 'forge.workorder.create'),
        ]);
    }

    public function workOrder(Request $request, string $step, WorkOrder $workOrder): View
    {
        $module = self::MODULES[$step] ?? abort(404);
        abort_unless($this->access->can($request->user(), $module['permission']), 403);

        return app(WorkOrderController::class)->show($request, $workOrder, $step);
    }
}
