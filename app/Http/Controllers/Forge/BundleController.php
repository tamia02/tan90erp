<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use App\Services\Forge\BundleService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function confirm(Request $request, WorkOrder $workOrder, AccessControlService $access, BundleService $service)
    {
        abort_unless($access->can($request->user(), $request->attributes->has('worker_operation') ? 'forge.bundle.confirm' : 'forge.production.approve'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $workOrder->site_id === null ? null : (int) $workOrder->site_id);
        $managed = (int) $workOrder->workflow_version >= 2;
        $data = $request->validate([
            'bundle_count' => ['required', 'integer', $managed ? 'min:1' : 'min:0'],
            'units_per_bundle' => ['required', 'numeric', $managed ? 'gt:0' : 'min:0'],
            'loose_units' => ['required', 'numeric', 'min:0'],
            'defective_qty' => ['required', 'numeric', 'min:0'],
            'defective_disposition' => ['required', 'in:scrap,rework,hold'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'manpower' => ['required', 'integer', 'min:1'],
            'materials' => [$managed ? 'nullable' : 'required', 'array'],
            'materials.*.inventory_item_id' => ['required', 'distinct', 'exists:inventory_items,id'],
            'materials.*.used_qty' => ['required', 'numeric', 'min:0'],
            'materials.*.scrap_qty' => ['required', 'numeric', 'min:0'],
            'materials.*.return_qty' => ['required', 'numeric', 'min:0'],
            'materials.*.reason' => ['nullable','string','max:500'],
        ]);
        $batch = $managed
            ? $service->confirmWithPackout($workOrder, $data, $request->user())
            : $service->confirm($workOrder, $data, $request->user());

        return back()->with('status', "Bundle confirmed; batch {$batch->batch_number} posted to FG stock once.");
    }
}
