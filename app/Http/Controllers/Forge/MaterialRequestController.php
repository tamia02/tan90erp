<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\Forge\MaterialRequestService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MaterialRequestController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder, AccessControlService $access, MaterialRequestService $service)
    {
        abort_unless($access->can($request->user(),'forge.material.issue') || ((int)$workOrder->workflow_version<3 && $access->can($request->user(),'forge.production.record')), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'distinct', 'exists:inventory_items,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.extra_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $lines = array_values(array_filter($data['lines'], fn ($line) => isset($line['qty']) && (float) $line['qty'] > 0));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Enter a quantity for at least one material before sending this request.']);
        }
        $id = $service->request($workOrder, $lines, $request->user());

        return back()->with('status', "Material request #{$id} sent to Production Manager / Shift Supervisor.");
    }

    public function issue(Request $request, WorkOrder $workOrder, int $materialRequest, AccessControlService $access, MaterialRequestService $service)
    {
        abort_unless($access->can($request->user(), 'forge.material.issue'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
        $data = $request->validate([
            'handover_started_at' => ['required', 'date'],
            'handover_ended_at' => ['required', 'date', 'after_or_equal:handover_started_at'],
            'manpower' => ['required', 'integer', 'min:1'],
        ]);
        $service->approveAndIssue($workOrder, $materialRequest, $data, $request->user());

        return back()->with('status', "Request #{$materialRequest} issued with FIFO lots and handover recorded.");
    }
}
