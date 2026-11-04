<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Deviation;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class DeviationController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.view'), 403);
        $site = SiteLocation::forUser($request->user());

        return view('forge.deviations.index', [
            'deviations' => Deviation::with(['workOrder.finishedGood', 'capaOwner'])
                ->when($site !== null, fn ($query) => $query->whereHas('workOrder', fn ($workOrder) => $workOrder->where('site_id', $site)))
                ->latest()->paginate(20),
            'workOrders' => WorkOrder::when($site !== null, fn ($query) => $query->where('site_id', $site))
                ->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.manage'), 403);

        $data = $request->validate([
            'work_order_id' => ['nullable', 'exists:forge_work_orders,id'],
            'source_type' => ['required', 'in:process,quality,machine,traceability'],
            'description' => ['required', 'string', 'max:2000'],
            'qty' => ['nullable', 'numeric', 'min:0.001'],
            'uom' => ['nullable', 'string', 'max:20'],
            'containment' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->assertSite($request, isset($data['work_order_id']) ? WorkOrder::findOrFail($data['work_order_id']) : null);

        $deviation = Deviation::create($data + ['status' => 'open', 'opened_by' => $request->user()->id]);
        AuditLogger::log('Deviation opened', $deviation->source_type.' — '.str($deviation->description)->limit(60), $deviation);

        return back()->with('status', 'Deviation opened.');
    }

    public function update(Request $request, Deviation $deviation)
    {
        abort_unless($this->access->can($request->user(), 'forge.deviation.manage'), 403);
        $this->assertSite($request, $deviation->workOrder);

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

    /**
     * Spins up an actual, executable work order for the deviated quantity —
     * reusing the full existing WO lifecycle (release, material issue, job
     * cards, final QC) rather than a parallel "rework order" entity, since a
     * rework build is not fundamentally different work.
     */
    public function createReworkOrder(Request $request, Deviation $deviation)
    {
        abort_unless($this->access->can($request->user(), 'forge.workorder.create'), 403);
        $this->assertSite($request, $deviation->workOrder);
        abort_unless($deviation->disposition === 'rework', 422, 'Only a deviation dispositioned to rework can spawn a rework order.');
        abort_if($deviation->reworkWorkOrder()->exists(), 422, 'A rework order already exists for this deviation.');

        $source = $deviation->workOrder;
        abort_unless($source, 422, 'This deviation has no source work order to rework from.');
        abort_unless($deviation->qty, 422, 'Enter the affected quantity before creating a rework order.');

        $wo = WorkOrder::create([
            'wo_number' => 'WO-'.now()->format('Y').'-'.str_pad((string) (WorkOrder::count() + 1), 4, '0', STR_PAD_LEFT),
            'source_deviation_id' => $deviation->id,
            'finished_good_id' => $source->finished_good_id,
            'bom_id' => $source->bom_id,
            'recipe_id' => $source->recipe_id,
            'routing_id' => $source->routing_id,
            'plant' => $source->plant,
            'inventory_fg_item_id' => $source->inventory_fg_item_id,
            'reusable_pcm_item_id' => $source->reusable_pcm_item_id,
            'site_id' => $source->site_id,
            'rm_warehouse_id' => $source->rm_warehouse_id,
            'fg_warehouse_id' => $source->fg_warehouse_id,
            'target_qty' => $deviation->qty,
            'uom' => $deviation->uom ?: $source->uom,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        if ($wo->routing_id) {
            $wo->load('routing.operations');
            foreach ($wo->routing->operations as $operation) {
                \App\Models\Forge\JobCard::create([
                    'work_order_id' => $wo->id,
                    'routing_operation_id' => $operation->id,
                    'sequence' => $operation->sequence,
                    'operation_name' => $operation->operation_name,
                    'planned_qty' => $wo->target_qty,
                    'status' => 'pending',
                ]);
            }
        }

        AuditLogger::log('Rework work order created', "{$wo->wo_number} from deviation #{$deviation->id}", $wo);

        return redirect()->route('forge.workorders.show', $wo)->with('status', "Rework work order {$wo->wo_number} created.");
    }

    private function assertSite(Request $request, ?WorkOrder $workOrder): void
    {
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && (! $workOrder || $workOrder->site_id === null || (int) $workOrder->site_id !== $site), 403);
    }
}
