<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Batch;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

// Backward trace: finished batch -> final QC -> production entries -> job
// cards -> work order -> released recipe/BOM/routing -> issued raw lots.
// The raw-lot/GRN half of forward trace lives in Origin; this screen shows
// the Forge portion end to end (FUNCTIONAL-FLOWS.md #12).
class BatchController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.batch.trace'), 403);

        return view('forge.batches.index', [
            'batches' => Batch::with('workOrder.finishedGood')
                ->when(SiteLocation::forUser($request->user()), fn ($query, $site) => $query->whereHas('workOrder', fn ($workOrder) => $workOrder->where('site_id', $site)))
                ->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, Batch $batch)
    {
        abort_unless($this->access->can($request->user(), 'forge.batch.trace'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $batch->workOrder?->site_id === null ? null : (int) $batch->workOrder->site_id);

        $batch->load([
            'workOrder.finishedGood', 'workOrder.bom', 'workOrder.recipe', 'workOrder.routing',
            'workOrder.materialIssues', 'workOrder.jobCards.machine', 'workOrder.jobCards.operator',
            'workOrder.qualityHolds', 'workOrder.finalQcResult', 'workOrder.productionEntries',
        ]);

        return view('forge.batches.show', ['batch' => $batch]);
    }
}
