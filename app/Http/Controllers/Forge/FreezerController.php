<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Batch;
use App\Models\Forge\Freezer;
use App\Models\Forge\FreezerLog;
use App\Services\Access\AccessControlService;
use App\Services\Forge\FreezerMonitoringService;
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

        $freezers = Freezer::with(['logs' => fn ($q) => $q->whereNull('ended_at')->with('batch')])
            ->orderBy('name')
            ->get();

        return view('forge.freezers.index', [
            'freezers' => $freezers,
            'availableBatches' => Batch::where('status', 'in_process')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'recentReadings' => \App\Models\Forge\FreezerReading::with('freezer')->latest('recorded_at')->limit(20)->get(),
        ]);
    }

    public function recordReading(Request $request, Freezer $freezer)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);

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

        $data = $request->validate(['batch_id' => ['required', 'exists:forge_batches,id']]);
        $batch = Batch::findOrFail($data['batch_id']);

        $this->monitoring->assignBatch($freezer, $batch);

        return back()->with('status', "{$batch->batch_number} assigned to {$freezer->name}.");
    }

    public function releaseBatch(Request $request, FreezerLog $log)
    {
        abort_unless($this->access->can($request->user(), 'forge.freezer.monitor'), 403);

        $this->monitoring->releaseBatch($log);

        return back()->with('status', 'Batch released from freezer.');
    }
}
