<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\QualityHold;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// In-process quality (IPQC): routing checkpoints create an inspection task;
// a fail creates a hold that stops job-card progress (WorkOrder::hasOpenHold,
// enforced in WorkOrderService) until an authorised quality role releases it.
class QualityHoldController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.ipqc.record'), 403);
        $site = SiteLocation::forUser($request->user());

        return view('forge.quality-holds.index', [
            'holds' => QualityHold::with(['workOrder.finishedGood', 'jobCard'])
                ->when($site !== null, fn ($query) => $query->whereHas('workOrder', fn ($workOrder) => $workOrder->where('site_id', $site)))
                ->latest()->paginate(20),
            'workOrders' => WorkOrder::whereIn('status', ['in_progress', 'reconciliation'])
                ->when($site !== null, fn ($query) => $query->where('site_id', $site))
                ->with('jobCards')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.ipqc.record'), 403);

        $data = $request->validate([
            'work_order_id' => ['required', 'exists:forge_work_orders,id'],
            'job_card_id' => ['nullable', Rule::exists('forge_job_cards', 'id')->where(fn ($query) => $query->where('work_order_id', $request->input('work_order_id')))],
            'checkpoint' => ['required', 'string', 'max:255'],
            'result' => ['required', 'in:pass,fail'],
            'evidence' => ['nullable', 'string', 'max:2000'],
        ]);
        $workOrder = WorkOrder::findOrFail($data['work_order_id']);
        $this->assertSite($request, $workOrder);

        $hold = QualityHold::create($data + [
            'status' => $data['result'] === 'fail' ? 'open' : 'released',
            'inspected_by' => $request->user()->id,
            'released_by' => $data['result'] === 'pass' ? $request->user()->id : null,
            'released_at' => $data['result'] === 'pass' ? now() : null,
        ]);

        AuditLogger::log('IPQC checkpoint recorded', $hold->checkpoint.' — '.$hold->result, $hold);

        return back()->with('status', $data['result'] === 'fail' ? 'Process hold created.' : 'IPQC pass recorded.');
    }

    public function release(Request $request, QualityHold $qualityHold)
    {
        abort_unless($this->access->can($request->user(), 'forge.ipqc.release'), 403);
        $this->assertSite($request, $qualityHold->workOrder);

        if ($qualityHold->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'Hold is not open.']);
        }

        $data = $request->validate(['evidence' => ['nullable', 'string', 'max:2000']]);

        $qualityHold->update($data + ['status' => 'released', 'released_by' => $request->user()->id, 'released_at' => now()]);
        AuditLogger::log('Process hold released', $qualityHold->checkpoint, $qualityHold);

        return back()->with('status', 'Hold released.');
    }

    private function assertSite(Request $request, WorkOrder $workOrder): void
    {
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
    }
}
