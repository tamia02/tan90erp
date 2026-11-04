<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\Shipment;
use App\Models\Workspace\WorkspaceTask;
use App\Services\Access\AccessControlService;
use App\Services\Forge\ProductionTaskService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class FinanceHandoffController extends Controller
{
    public function review(Request $request, WorkspaceTask $task, AccessControlService $access)
    {
        abort_unless($access->can($request->user(), 'finance.review.view'), 403);
        abort_unless($task->target_permission === 'finance.review.view'
            && $task->linked_type === Shipment::class
            && Shipment::whereKey($task->linked_id)
                ->whereNotNull('released_at')
                ->whereIn('status', ['released', 'closed'])
                ->exists(), 422);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $task->site_id === null ? null : (int) $task->site_id);
        app(ProductionTaskService::class)->done($task->event_key, $request->user()->id);

        return back()->with('status', 'Shipment finance review acknowledged.');
    }
}
