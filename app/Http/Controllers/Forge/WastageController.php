<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WastageRecord;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class WastageController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.wastage.record'), 403);
        $site = SiteLocation::forUser($request->user());

        return view('forge.wastage.index', [
            'records' => WastageRecord::with('workOrder')
                ->when($site !== null, fn ($query) => $query->whereHas('workOrder', fn ($workOrder) => $workOrder->where('site_id', $site)))
                ->latest()->paginate(20),
            'workOrders' => WorkOrder::whereNotIn('status', ['closed', 'draft'])
                ->when($site !== null, fn ($query) => $query->where('site_id', $site))
                ->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.wastage.record'), 403);

        $data = $request->validate([
            'work_order_id' => ['required', 'exists:forge_work_orders,id'],
            'item_name' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $workOrder = WorkOrder::findOrFail($data['work_order_id']);
        $this->assertSite($request, $workOrder);

        $record = WastageRecord::create($data + ['disposition' => 'pending', 'recorded_by' => $request->user()->id]);
        AuditLogger::log('Wastage recorded', $record->item_name.' — '.$record->qty.' '.$record->uom, $record);

        return back()->with('status', 'Wastage recorded.');
    }

    public function approve(Request $request, WastageRecord $wastage)
    {
        abort_unless($this->access->can($request->user(), 'forge.wastage.approve'), 403);
        $this->assertSite($request, $wastage->workOrder);

        $data = $request->validate([
            'disposition' => ['required', 'in:rework,recover,return,destruction,approved_scrap'],
        ]);

        $wastage->update($data + ['approved_by' => $request->user()->id, 'approved_at' => now()]);
        AuditLogger::log('Wastage disposition approved', $wastage->item_name.' — '.$data['disposition'], $wastage);

        return back()->with('status', 'Wastage disposition recorded.');
    }

    private function assertSite(Request $request, WorkOrder $workOrder): void
    {
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
    }
}
