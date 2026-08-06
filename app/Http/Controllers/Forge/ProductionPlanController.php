<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\ProductionPlan;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ProductionPlanController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.view'), 403);

        return view('forge.plans.index', [
            'plans' => ProductionPlan::with(['finishedGood', 'creator', 'approver'])->latest()->paginate(20),
            'finishedGoods' => FinishedGood::active()->approvalStatus('approved')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.create'), 403);

        $data = $request->validate([
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'plant' => ['nullable', 'string', 'max:100'],
            'target_qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $plan = ProductionPlan::create($data + [
            'plan_number' => 'PP-'.now()->format('Y').'-'.str_pad((string) (ProductionPlan::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::log('Production plan created', $plan->plan_number, $plan);

        return back()->with('status', "Plan {$plan->plan_number} created.");
    }

    public function approve(Request $request, ProductionPlan $plan)
    {
        abort_unless($this->access->can($request->user(), 'forge.plan.approve'), 403);
        abort_unless($plan->status === 'draft', 422, 'Only a draft plan can be approved.');

        $plan->update(['status' => 'frozen', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        AuditLogger::log('Production plan approved and frozen', $plan->plan_number, $plan);

        return back()->with('status', "Plan {$plan->plan_number} approved and frozen.");
    }
}
