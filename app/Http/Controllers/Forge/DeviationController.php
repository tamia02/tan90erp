<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Deviation;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class DeviationController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.view'), 403);

        return view('forge.deviations.index', [
            'deviations' => Deviation::with(['workOrder.finishedGood', 'capaOwner'])->latest()->paginate(20),
            'workOrders' => WorkOrder::orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.manage'), 403);

        $data = $request->validate([
            'work_order_id' => ['nullable', 'exists:forge_work_orders,id'],
            'source_type' => ['required', 'in:process,quality,machine,traceability'],
            'description' => ['required', 'string', 'max:2000'],
            'containment' => ['nullable', 'string', 'max:2000'],
        ]);

        $deviation = Deviation::create($data + ['status' => 'open', 'opened_by' => $request->user()->id]);
        AuditLogger::log('Deviation opened', $deviation->source_type.' — '.str($deviation->description)->limit(60), $deviation);

        return back()->with('status', 'Deviation opened.');
    }

    public function update(Request $request, Deviation $deviation)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.manage'), 403);

        $data = $request->validate([
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'disposition' => ['nullable', 'in:use_as_is,rework,reject,return'],
            'capa_action' => ['nullable', 'string', 'max:2000'],
            'capa_owner_id' => ['nullable', 'exists:users,id'],
            'capa_target_date' => ['nullable', 'date'],
            'status' => ['required', 'in:open,investigating,disposed,capa_open,closed'],
        ]);

        if ($data['status'] === 'closed' && ! $deviation->effectiveness_check && ! $request->filled('effectiveness_check')) {
            return back()->withErrors(['effectiveness_check' => 'Effectiveness check is required before closing a deviation.']);
        }

        $data['effectiveness_check'] = $request->input('effectiveness_check', $deviation->effectiveness_check);
        $data['closed_at'] = $data['status'] === 'closed' ? now() : null;

        $deviation->update($data);
        AuditLogger::log('Deviation updated', $deviation->status, $deviation);

        return back()->with('status', 'Deviation updated.');
    }
}
