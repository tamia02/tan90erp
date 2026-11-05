<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\ProductionFgMapping;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use App\Services\Forge\ProductionTaskService;
use App\Services\Forge\SalesProductionService;
use App\Support\SiteLocation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionPlanController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.view'), 403);

        $site = SiteLocation::forUser($request->user());
        $locations = SiteLocation::visibleLocations();
        $mappings = ProductionFgMapping::query()
            ->where('status', 'approved')
            ->whereIn('site_id', $locations->pluck('id'))
            ->get(['site_id', 'finished_good_id']);

        return view('forge.plans.index', [
            'plans' => ProductionPlan::with(['finishedGood', 'creator', 'approver', 'site', 'sourceOrderLine.order', 'workOrders:id,production_plan_id,wo_number,status,target_qty'])
                ->when($site !== null, fn ($query) => $query->where('site_id', $site))
                ->latest()->paginate(20),
            'finishedGoods' => FinishedGood::active()->approvalStatus('approved')->orderBy('name')->get(),
            'locations' => $locations,
            'mappedPairs' => $mappings->map(fn ($mapping) => $mapping->site_id.':'.$mapping->finished_good_id)->all(),
            'inventoryFgCount' => InventoryItem::where('item_type', 'fg')->where('active', true)->count(),
            'canCreate' => $this->access->can($request->user(), 'forge.plan.create'),
            'canApprove' => $this->access->can($request->user(), 'forge.plan.approve'),
            'canCreateWorkOrder' => $this->access->can($request->user(), 'forge.workorder.create'),
            'canViewWorkOrders' => $this->access->can($request->user(), 'forge.workorder.view'),
            'canViewSalesOrders' => $this->access->can($request->user(), 'flow.order.view'),
        ]);
    }

    public function show(Request $request, ProductionPlan $plan)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.view'), 403);
        $this->assertSite($request, $plan);

        $plan->load(['finishedGood', 'creator', 'approver', 'site', 'sourceOrderLine.order', 'workOrders.finishedGood', 'workOrders.batch.inventoryLot']);

        $canEdit = ! $plan->source_order_line_id && $plan->workOrders->isEmpty()
            && in_array($plan->status, ['draft', 'frozen'], true)
            && $this->access->can($request->user(), 'forge.plan.create');
        $locations = $canEdit ? SiteLocation::visibleLocations() : collect();

        return view('forge.plans.show', [
            'plan' => $plan,
            'assignedQty' => (float) $plan->workOrders->sum('target_qty'),
            'planReady' => $this->isReadyToProduce($plan),
            'canEdit' => $canEdit,
            'finishedGoods' => $canEdit ? FinishedGood::active()->approvalStatus('approved')->orderBy('name')->get() : collect(),
            'locations' => $locations,
            'mappedPairs' => $canEdit ? ProductionFgMapping::where('status', 'approved')
                ->whereIn('site_id', $locations->pluck('id'))->get(['site_id', 'finished_good_id'])
                ->map(fn ($mapping) => $mapping->site_id.':'.$mapping->finished_good_id)->all() : [],
            'canApprove' => $this->access->can($request->user(), 'forge.plan.approve'),
            'canCreateWorkOrder' => $this->access->can($request->user(), 'forge.workorder.create'),
            'canViewWorkOrders' => $this->access->can($request->user(), 'forge.workorder.view'),
            'canViewSalesOrders' => $this->access->can($request->user(), 'flow.order.view'),
        ]);
    }

    public function update(Request $request, ProductionPlan $plan)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.create'), 403);
        $this->assertSite($request, $plan);
        $data = $request->validate([
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'site_id' => ['required', 'exists:tan90_locations,id'],
            'plant' => ['nullable', 'string', 'max:100'],
            'target_qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && $site !== (int) $data['site_id'], 403);
        $finishedGood = FinishedGood::active()->approvalStatus('approved')->find($data['finished_good_id']);
        if (! $finishedGood || Str::upper(trim($data['uom'])) !== Str::upper(trim((string) $finishedGood->uom))) {
            throw ValidationException::withMessages(['finished_good_id' => 'Choose an approved finished good and use its unit of measure.']);
        }
        $this->assertReadyToProduce((int) $data['site_id'], $finishedGood);
        $data['uom'] = $finishedGood->uom;

        DB::transaction(function () use ($plan, $data, $request): void {
            $lockedPlan = ProductionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertSite($request, $lockedPlan);
            if ($lockedPlan->source_order_line_id || ! in_array($lockedPlan->status, ['draft', 'frozen'], true)
                || $lockedPlan->workOrders()->exists()) {
                throw ValidationException::withMessages(['status' => 'This plan is linked to sales demand or already has a work order, so its approved quantities cannot be edited.']);
            }
            $lockedPlan->update($data + ['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
            AuditLogger::log('Production plan revised for approval', $lockedPlan->plan_number, $lockedPlan);
        });

        return redirect()->route('forge.plans.show', $plan)->with('status', 'Plan updated. Approve and freeze it again before creating a work order.');
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.create'), 403);

        $data = $request->validate([
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'site_id' => ['required', 'exists:tan90_locations,id'],
            'plant' => ['nullable', 'string', 'max:100'],
            'target_qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && $site !== (int) $data['site_id'], 403);

        $finishedGood = FinishedGood::active()->approvalStatus('approved')->find($data['finished_good_id']);
        if (! $finishedGood) {
            throw ValidationException::withMessages(['finished_good_id' => 'Choose an active, approved finished good.']);
        }
        if (Str::upper(trim($data['uom'])) !== Str::upper(trim((string) $finishedGood->uom))) {
            throw ValidationException::withMessages(['uom' => 'Use the unit of measure from the selected finished-good master.']);
        }
        $this->assertReadyToProduce((int) $data['site_id'], $finishedGood);
        $data['uom'] = $finishedGood->uom;

        $plan = ProductionPlan::create($data + [
            'plan_number' => 'PP-M-'.Str::upper(Str::ulid()),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log('Production plan created', $plan->plan_number, $plan);

        return redirect()->route('forge.plans.show', $plan)->with('status', "Plan {$plan->plan_number} created. Approve it before creating a work order.");
    }

    public function approve(Request $request, ProductionPlan $plan)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.approve'), 403);
        $this->assertSite($request, $plan);
        if ($plan->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'This plan is already frozen or closed. Only draft plans can be approved. Refresh the plan list to see its current status.']);
        }
        $finishedGood = FinishedGood::active()->approvalStatus('approved')->find($plan->finished_good_id);
        if (! $finishedGood || ! $plan->site_id) {
            throw ValidationException::withMessages(['finished_good_id' => 'This plan needs an active approved product and production site before approval.']);
        }
        $this->assertReadyToProduce((int) $plan->site_id, $finishedGood);
        if (Str::upper(trim((string) $plan->uom)) !== Str::upper(trim((string) $finishedGood->uom))) {
            throw ValidationException::withMessages(['uom' => 'Plan unit does not match the approved finished-good master.']);
        }

        $plan->update(['status' => 'frozen', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        if ($plan->source_order_line_id) {
            app(ProductionTaskService::class)->done('plan:'.$plan->id.':approve', $request->user()->id);
            app(ProductionTaskService::class)->open('plan:'.$plan->id.':workorder', 'Create work order for '.$plan->plan_number,
                'forge.workorder.create', (int) $plan->site_id, $request->user()->id, $plan);
        }
        AuditLogger::log('Production plan approved and frozen', $plan->plan_number, $plan);

        return redirect()->route('forge.plans.show', $plan)->with('status', "Plan {$plan->plan_number} approved. Create its work order next.");
    }

    private function assertReadyToProduce(int $siteId, FinishedGood $finishedGood): void
    {
        app(SalesProductionService::class)->approvedMapping($siteId, (int) $finishedGood->id);

        $hasRecipe = Recipe::where('tan90_finished_good_id', $finishedGood->id)
            ->where('status', 'active')->where('approval_status', 'approved')->exists();
        $hasBom = Bom::where('tan90_finished_good_id', $finishedGood->id)
            ->where('status', 'active')->where('approval_status', 'approved')->exists();
        if (! $hasRecipe && ! $hasBom) {
            throw ValidationException::withMessages(['finished_good_id' => 'Approve a recipe or BOM for this finished good before planning production.']);
        }
    }

    private function isReadyToProduce(ProductionPlan $plan): bool
    {
        if (! $plan->site_id || ! $plan->finishedGood
            || Str::upper(trim((string) $plan->uom)) !== Str::upper(trim((string) $plan->finishedGood->uom))) {
            return false;
        }
        try {
            $this->assertReadyToProduce((int) $plan->site_id, $plan->finishedGood);
            return true;
        } catch (ValidationException|ModelNotFoundException) {
            return false;
        }
    }

    private function assertSite(Request $request, ProductionPlan $plan): void
    {
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && (! $plan->site_id || (int) $plan->site_id !== $site), 403);
    }
}
