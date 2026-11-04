<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Flow\CustomerOrder;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class ProductionFlowController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->can($user, 'forge.dashboard.view'), 403);

        $site = SiteLocation::forUser($user);
        $orders = CustomerOrder::withCount('lines')->whereNotNull('site_id')
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))->latest()->limit(50)->get();
        $order = $request->filled('order') ? CustomerOrder::with(['lines.finishedGood', 'lines.allocations.inventoryLot'])
            ->whereNotNull('site_id')->when($site !== null, fn ($q) => $q->where('site_id', $site))
            ->findOrFail($request->integer('order')) : null;
        $lineIds = $order?->lines->pluck('id') ?? collect();
        $plans = $order ? ProductionPlan::whereIn('source_order_line_id', $lineIds)
            ->with(['workOrders.batch', 'workOrders.finalQcResult', 'workOrders.materialIssues:id,work_order_id'])
            ->orderBy('id')->get() : collect();
        $workOrders = $plans->flatMap->workOrders;
        $accepted = (float) $workOrders->sum(fn ($wo) => (float) ($wo->batch?->qty ?? 0));
        $ordered = (float) ($order?->lines->sum('qty_ordered') ?? 0);
        $allocated = (float) ($order?->lines->sum('qty_allocated') ?? 0);
        $putawayAllocated = $order
            ? (float) $order->lines->sum(fn ($line) => $line->allocations->sum(fn ($allocation) =>
                $allocation->inventoryLot?->bin_id ? (float) $allocation->qty : 0
            ))
            : 0.0;
        $canWorkOrders = $this->can($user, 'forge.workorder.view');
        $canPlans = $this->can($user, 'forge.plan.view');
        $canProduction = $this->can($user, 'forge.production.record');
        $canBundle = $this->can($user, 'forge.production.approve');
        $canFinalQc = $this->can($user, 'forge.finalqc.record');
        $canInventory = $this->can($user, 'flow.inventory.view');
        $handoffProgressState = 'upcoming';

        $steps = [
            [
                'title' => 'Start: Planning & RM/PM Issue', 'owner' => 'Planner / Production Manager',
                'description' => 'Select the finished good, quantity, site, and warehouse. Link the approved BOM or recipe and exact Inventory FG SKU to the work order. Reserve and issue RM/PM by FIFO, then record handover start/end times and manpower.',
                'input' => 'Approved plan, product definition, FG SKU, RM/PM recipe, site, and warehouses.',
                'control' => 'Stock is checked by site and warehouse. Material consumption is posted once to the Inventory ledger at issue.',
                'outcome' => 'Traceable work order with issued material.',
                'url' => $canWorkOrders ? route('forge.modules.show', ['step' => 'materials']) : ($canPlans ? route('forge.plans.index') : null),
                'action' => 'Open work orders',
            ],
            [
                'title' => 'Manual PCM mixing', 'owner' => 'Mixing operator',
                'description' => 'Prepare one complete PCM solution batch. Record the material quantities and UOM, start/end time, manpower, output, fill-machine time, and any loss. A second mix is not allowed for the same work order.',
                'input' => 'Active work order with issued material.',
                'control' => 'Mix is recorded once. Quality hold checks must pass before filling.',
                'outcome' => 'One traceable PCM batch ready for continuous feed.',
                'url' => $canProduction && $canWorkOrders ? route('forge.modules.show', ['step' => 'mixing']) : null,
                'action' => 'Record mixing',
            ],
            [
                'title' => 'Filling & Sealing', 'owner' => 'Production Operator',
                'description' => 'Feed the prepared PCM continuously through the filling and sealing line. Record each stage and its machine mode, output, manpower, power, idle time, and breakdown reason. A pause is downtime, not a new PCM pour.',
                'input' => 'One PCM mix, one continuous feed entry, and the filling and sealing counts.',
                'control' => 'Filling and sealing are tracked separately; sealing cannot exceed filled output. The continuous feed is entered once for the whole work order.',
                'outcome' => 'Traceable operation records for production approval and QC.',
                'url' => $canProduction && $canWorkOrders ? route('forge.modules.show', ['step' => 'filling-sealing']) : null,
                'action' => 'Open production',
            ],
            [
                'title' => 'QC: Leak & Pressure Tests', 'owner' => 'QC / Quality Manager',
                'description' => 'Record leak, pressure and physical checks; count accepted, rejected and rework units, and record PCM recovered from failed units. Recovered PCM remains on this work order for rework, never in warehouse stock.',
                'input' => 'Approved production output and inspection results.',
                'control' => 'QC quantities cannot exceed approved output, and both tests must pass before release.',
                'outcome' => 'QC-approved FG, with recovery and every rework round visible in the order trace.',
                'url' => $canFinalQc ? route('forge.modules.show', ['step' => 'quality']) : null,
                'action' => 'Open final QC',
            ],
            [
                'title' => 'Bundle · Consumption & FG', 'owner' => 'Store Manager / Production Manager',
                'description' => 'Count real bundles and loose accepted units. On confirmation, the system calculates RM/PM use from the approved Inventory BOM, returns unused issued balance, and posts one FG batch.',
                'input' => 'Released final QC, physical bundle count, approved Inventory BOM, and issued RM/PM.',
                'control' => 'Bundle units plus loose units must equal QC accepted units. Material use cannot exceed issued stock. Rejected or rework units stay outside saleable FG.',
                'outcome' => 'Physical bundle record, automatic material reconciliation, and QC-accepted FG posted once.',
                'url' => $canWorkOrders ? route('forge.modules.show', ['step' => 'consumption-fg']) : null,
                'action' => 'Count bundles and confirm FG',
            ],
            [
                'title' => 'FG Store · master bin', 'owner' => 'FG Store',
                'description' => 'Receive the released batch and put it away in an active master bin in the mapped warehouse. Putaway allocates available units to the linked sales order.',
                'input' => 'QC-released FG batch, active master bin, putaway time, and manpower.',
                'control' => 'Putaway does not increase FG ledger stock again. SKU, site, and warehouse must match exactly.',
                'outcome' => 'Available FG lot and sales-order allocation.',
                'url' => $canInventory ? route('forge.modules.show', ['step' => 'finished-goods']) : null,
                'action' => 'Open FG inventory',
            ],
        ];

        if ($order) {
            $first = $workOrders->first();
            $workOrderCount = $workOrders->count();
            $issuedCount = $workOrders->filter(fn ($wo) => $wo->materialIssues->isNotEmpty())->count();
            $mixingWorkOrderIds = \Illuminate\Support\Facades\DB::table('forge_mixing_records')
                ->whereIn('work_order_id', $workOrders->pluck('id'))->distinct()->pluck('work_order_id');
            $mixingCount = $mixingWorkOrderIds->count();
            $reworkWorkOrders = $workOrders->where('status', 'rework');
            $productionCount = $workOrders->filter(fn ($wo) => (float) $wo->good_qty > 0)->count();
            $qcCount = $workOrders->filter(fn ($wo) => $wo->finalQcResult?->released_at !== null && $wo->finalQcResult?->result === 'released')->count();
            $bundleRecordModes = \Illuminate\Support\Facades\DB::table('forge_bundle_records')
                ->whereIn('work_order_id', $workOrders->pluck('id'))
                ->get(['work_order_id', 'auto_reconciled'])->keyBy('work_order_id');
            $bundleCount = $workOrders->filter(fn ($wo) => $bundleRecordModes->has($wo->id)
                && $wo->batch !== null && in_array((string) $wo->status, ['released_to_fg', 'closed'], true))->count();
            $automaticBundleCount = $workOrders->filter(fn ($wo) => (bool) ($bundleRecordModes->get($wo->id)?->auto_reconciled ?? false))->count();
            $historicalManualBundleCount = $workOrders->filter(fn ($wo) => $bundleRecordModes->has($wo->id)
                && $wo->batch !== null && ! (bool) ($bundleRecordModes->get($wo->id)?->auto_reconciled ?? false))->count();
            $orderInDispatch = in_array($order->status, ['in_transit', 'pod_received', 'closed'], true);
            $handoffProgressState = $orderInDispatch ? 'complete' : (in_array($order->status, ['allocated', 'picked', 'packing', 'packed', 'dispatch_planned'], true) ? 'current' : 'upcoming');
            $states = [
                [
                    $plans->isEmpty() ? 'Waiting for plan' : ($issuedCount === $workOrderCount && $workOrderCount > 0 ? 'Material issued' : ($workOrderCount === 0 ? 'Plan ready' : 'Work orders created')),
                    $plans->sum('target_qty').' planned; '.$issuedCount.' / '.$workOrderCount.' WO issued',
                    $issuedCount === $workOrderCount && $workOrderCount > 0 ? 'FIFO issue complete; continue to manual PCM mixing.' : ($plans->isEmpty() ? 'Release sales order to generate shortage plan.' : 'Approve the material request and issue FIFO lots.'),
                ],
                [
                    $workOrderCount > 0 && $mixingCount === $workOrderCount ? 'Mixing recorded' : ($mixingCount > 0 ? 'Mixing partial' : 'Pending'),
                    $mixingCount.' / '.$workOrderCount.' WO recorded',
                    $mixingCount === $workOrderCount && $workOrderCount > 0 ? 'Mixing complete; proceed to filling and sealing.' : 'Issue requested material and record manual PCM mixing.',
                ],
                [
                    $workOrderCount > 0 && $productionCount === $workOrderCount ? 'Production approved' : ($productionCount > 0 ? 'Production partial' : 'Pending'),
                    $workOrders->sum('good_qty').' approved good · '.$productionCount.' / '.$workOrderCount.' WO',
                    $productionCount === $workOrderCount && $workOrderCount > 0 ? 'Production approved; proceed to leak and pressure QC.' : 'Record filling, sealing and approve output.',
                ],
                [
                    $workOrderCount > 0 && $qcCount === $workOrderCount ? 'QC released' : ($qcCount > 0 ? 'QC partial' : 'Pending'),
                    $workOrders->sum(fn ($wo) => (float) ($wo->finalQcResult?->accepted_qty ?? 0)).' QC accepted · '.$qcCount.' / '.$workOrderCount.' WO',
                    $qcCount === $workOrderCount && $workOrderCount > 0 ? 'QC released; count physical bundles before FG posting.' : 'Complete leak, pressure and physical tests, then Quality approval.',
                ],
                [
                    $workOrderCount > 0 && $automaticBundleCount === $workOrderCount ? 'Physical bundles confirmed · FG posted' : ($bundleCount === $workOrderCount && $historicalManualBundleCount > 0 ? 'FG posted · manual historical' : ($accepted > 0 ? 'FG posting partial' : 'Awaiting QC release')),
                    $accepted.' QC-accepted FG · '.$automaticBundleCount.' BOM-reconciled, '.$historicalManualBundleCount.' historical manual / '.$workOrderCount.' WO',
                    $bundleCount === $workOrderCount && $workOrderCount > 0
                        ? ($historicalManualBundleCount > 0
                            ? 'FG is posted. This older work order uses historical manual reconciliation; new work orders require physical bundle count.'
                            : 'Consumption reconciled and FG posted once; receive and bin the stock.')
                        : 'After QC release, count physical bundles; resolve any material shortage before FG posting.',
                ],
                [
                    $orderInDispatch ? 'Dispatched' : ($allocated >= $ordered && $ordered > 0 ? 'Order allocated' : 'Awaiting FG'),
                    $putawayAllocated.' / '.$ordered.' allocated from binned FG',
                    $orderInDispatch ? 'FG store complete; shipment is '.$order->status.'.' : ($allocated >= $ordered && $ordered > 0 ? 'All units are allocated; proceed to pick, pack and dispatch.' : 'Receive FG, put away in master bin and cover shortage.'),
                ],
            ];
            if ($reworkWorkOrders->isNotEmpty()) {
                $states[2] = [
                    'Same-order rework needed',
                    $reworkWorkOrders->count().' WO returned by QC',
                    'Resume the existing work order, record recovered PCM reused, and send the corrected units through QC again.',
                ];
                $states[3] = [
                    'QC repeat required',
                    $qcCount.' / '.$workOrderCount.' WO released after QC',
                    'Recovered PCM is kept on its own order. Reinspect leak and pressure after same-order rework.',
                ];
            }
            $complete = [
                $workOrderCount > 0 && $issuedCount === $workOrderCount,
                $workOrderCount > 0 && $mixingCount === $workOrderCount,
                $workOrderCount > 0 && $productionCount === $workOrderCount,
                $workOrderCount > 0 && $qcCount === $workOrderCount,
                $workOrderCount > 0 && $bundleCount === $workOrderCount,
                $ordered > 0 && ($allocated >= $ordered || $orderInDispatch),
            ];
            $completedSteps = count(array_filter($complete));
            $progressPercent = (int) round($completedSteps / count($steps) * 100);
            $currentStepIndex = array_search(false, $complete, true);
            foreach ($steps as $i => &$step) {
                [$step['live_status'], $step['live_qty'], $step['live_blocker']] = $states[$i];
                $step['progress_state'] = $complete[$i] ? 'complete' : ($currentStepIndex === $i ? 'current' : 'upcoming');
                if ($complete[$i]) {
                    $step['action'] = 'Review records';
                }
                if ($canWorkOrders && $step['url'] !== null && in_array($i, [0, 1, 2, 4], true)) {
                    $targetWorkOrder = match ($i) {
                        1 => $workOrders->first(fn ($wo) => ! $mixingWorkOrderIds->contains($wo->id)) ?? $first,
                        2 => $workOrders->first(fn ($wo) => (float) $wo->good_qty <= 0) ?? $first,
                        default => $first,
                    };
                    if ($targetWorkOrder) {
                        $module = [0 => 'materials', 1 => 'mixing', 2 => 'filling-sealing', 4 => 'consumption-fg'][$i];
                        $step['url'] = route('forge.modules.workorder', ['step' => $module, 'workOrder' => $targetWorkOrder]);
                    }
                }
                if ($first && $i === 3 && $canFinalQc) {
                    $step['url'] = route('forge.final-qc.index', ['work_order' => $first->id]);
                }
                if ($first && $i === 5 && $canInventory) {
                    $step['url'] = route('flow.inventory.index', ['work_order' => $first->id]);
                }
            }
            unset($step);
        } else {
            $completedSteps = 0;
            $progressPercent = 0;
            $currentStepIndex = null;
            foreach ($steps as &$step) {
                $step['progress_state'] = 'upcoming';
            }
            unset($step);
        }

        return view('forge.production-flow', compact('steps', 'orders', 'order', 'plans', 'workOrders', 'ordered', 'allocated', 'accepted')
            + compact('completedSteps', 'progressPercent', 'currentStepIndex', 'handoffProgressState')
            + ['canWorkOrders' => $canWorkOrders]);
    }

    private function can(User $user, string $permission): bool
    {
        return ($user->super_admin && $user->access_mode !== 'legacy') || $this->access->can($user, $permission);
    }
}
