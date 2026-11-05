<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Batch;
use App\Models\Forge\Freezer;
use App\Models\Forge\FreezerLog;
use App\Models\Tan90\MasterData\Location;
use App\Services\Access\AccessControlService;
use App\Services\Forge\FreezerMonitoringService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class FreezerController extends Controller
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly FreezerMonitoringService $monitoring,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.view'), 403);
        $site = SiteLocation::forUser($request->user());

        $freezers = Freezer::with(['logs' => fn ($q) => $q->whereNull('ended_at')->with('batch')])
            ->with('site')
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))
            ->orderBy('name')
            ->get();

        return view('forge.freezers.index', [
            'freezers' => $freezers,
            'sites' => Location::whereIn('type', ['Manufacturing', 'Warehouse'])->when($site !== null, fn ($q) => $q->whereKey($site))->orderBy('name')->get(),
            'site' => $site,
            'availableBatches' => Batch::with('workOrder')->where('status', 'in_process')->whereHas('workOrder', fn ($q) => $q->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'recentReadings' => \App\Models\Forge\FreezerReading::with('freezer.site')->whereHas('freezer', fn ($q) => $q->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))->latest('recorded_at')->limit(20)->get(),
        ]);
    }

    public function assignSite(Request $request, Freezer $freezer)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);
        abort_unless($freezer->site_id === null, 422, 'Freezer is already assigned to a site.');
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null, 403);
        $data = $request->validate(['site_id' => ['required', 'exists:tan90_locations,id']]);
        $location = Location::findOrFail($data['site_id']);
        abort_unless(in_array($location->type, ['Manufacturing', 'Warehouse'], true), 422);
        $freezer->update(['site_id' => $location->id]);
        \App\Services\AuditLogger::log('Freezer assigned to site', $freezer->name.' -> '.$location->name, $freezer);

        return back()->with('status', "{$freezer->name} assigned to {$location->name}.");
    }

    public function recordReading(Request $request, Freezer $freezer)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);
        $this->assertVisibleFreezer($request, $freezer);

        $data = $request->validate([
            'temperature' => ['required', 'numeric', 'between:-100,100'],
            'humidity' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $reading = $this->monitoring->recordReading($freezer, (float) $data['temperature'], isset($data['humidity']) ? (float) $data['humidity'] : null);

        return back()->with('status', $reading->is_alert
            ? "Reading recorded — {$reading->temperature}°C is out of range, an exception has been raised."
            : "Reading recorded for {$freezer->name}.");
    }

    public function assignBatch(Request $request, Freezer $freezer)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);
        $this->assertVisibleFreezer($request, $freezer);

        $data = $request->validate(['batch_id' => ['required', 'exists:forge_batches,id']]);
        $batch = Batch::findOrFail($data['batch_id']);
        abort_unless((int) $batch->workOrder?->site_id === (int) $freezer->site_id, 422, 'Batch and freezer must belong to the same site.');

        $this->monitoring->assignBatch($freezer, $batch);

        return back()->with('status', "{$batch->batch_number} assigned to {$freezer->name}.");
    }

    public function releaseBatch(Request $request, FreezerLog $log)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);
        $this->assertVisibleFreezer($request, $log->freezer);

        $this->monitoring->releaseBatch($log);

        return back()->with('status', 'Batch released from freezer.');
    }

    private function assertVisibleFreezer(Request $request, Freezer $freezer): void
    {
        $site = SiteLocation::forUser($request->user());
        abort_if($freezer->site_id === null || ($site !== null && (int) $freezer->site_id !== $site), 403);
    }
}
