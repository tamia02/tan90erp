<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Machine;
use App\Models\Forge\MachineDowntimeEvent;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use App\Support\SiteLocation;
use App\Models\Tan90\MasterData\Location;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.view'), 403);

        $site = SiteLocation::forUser($request->user());
        $machines = Machine::with(['site', 'workCenter', 'downtimeEvents' => fn ($q) => $q->whereNull('ended_at')])
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))
            ->orderBy('name')->get();

        return view('forge.machines.index', [
            'machines' => $machines,
            'sites' => Location::whereIn('type', ['Manufacturing', 'Warehouse'])->when($site !== null, fn ($q) => $q->whereKey($site))->orderBy('name')->get(),
            'site' => $site,
            'recentDowntime' => MachineDowntimeEvent::with('machine.site')->whereHas('machine', fn ($q) => $q->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))->latest('started_at')->limit(20)->get(),
        ]);
    }

    public function assignSite(Request $request, Machine $machine)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);
        abort_unless($machine->site_id === null, 422, 'Machine is already assigned to a site.');
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null, 403);
        $data = $request->validate(['site_id' => ['required', 'exists:tan90_locations,id']]);
        $location = Location::findOrFail($data['site_id']);
        abort_unless(in_array($location->type, ['Manufacturing', 'Warehouse'], true), 422);
        $machine->update(['site_id' => $location->id]);
        AuditLogger::log('Machine assigned to site', $machine->name.' -> '.$location->name, $machine);

        return back()->with('status', "{$machine->name} assigned to {$location->name}.");
    }

    public function startDowntime(Request $request, Machine $machine)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);
        $this->assertVisibleMachine($request, $machine);

        if ($machine->openDowntimeEvent()) {
            throw ValidationException::withMessages(['machine' => 'Machine already has an open downtime event.']);
        }

        $data = $request->validate([
            'category' => ['required', 'string', 'max:50'],
            'severity' => ['required', 'string', 'max:20'],
            'observation' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = MachineDowntimeEvent::create($data + [
            'machine_id' => $machine->id,
            'owner_user_id' => $request->user()->id,
            'started_at' => now(),
        ]);
        $machine->update(['state' => 'down']);

        AuditLogger::log('Machine downtime started', $machine->name.' — '.$event->category, $event);

        return back()->with('status', "Downtime logged for {$machine->name}.");
    }

    public function closeDowntime(Request $request, MachineDowntimeEvent $downtime)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);
        $this->assertVisibleMachine($request, $downtime->machine);
        abort_unless($downtime->ended_at === null, 422, 'Downtime event already closed.');

        $data = $request->validate([
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
        ]);

        $downtime->update($data + ['ended_at' => now()]);
        $hasRunningJob = \Illuminate\Support\Facades\DB::table('forge_machine_run_intervals')
            ->where('machine_id', $downtime->machine_id)->whereNull('ended_at')->exists();
        $downtime->machine->update(['state' => $hasRunningJob ? 'running' : 'idle']);

        AuditLogger::log('Machine downtime closed', $downtime->machine->name, $downtime);

        return back()->with('status', "Downtime closed for {$downtime->machine->name} ({$downtime->durationMinutes()} min).");
    }

    public function setState(Request $request, Machine $machine)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);
        $this->assertVisibleMachine($request, $machine);

        $data = $request->validate(['state' => ['required', 'in:idle,setup,running,maintenance']]);
        if (in_array($data['state'], ['idle', 'setup', 'maintenance'], true)
            && \Illuminate\Support\Facades\DB::table('forge_machine_run_intervals')
                ->where('machine_id', $machine->id)->whereNull('ended_at')->exists()) {
            throw ValidationException::withMessages(['state' => 'Pause the running job card or log a machine stop first.']);
        }
        $machine->update($data);

        AuditLogger::log('Machine state changed', $machine->name.' -> '.$data['state'], $machine);

        return back()->with('status', "{$machine->name} set to {$data['state']}.");
    }

    private function assertVisibleMachine(Request $request, Machine $machine): void
    {
        $site = SiteLocation::forUser($request->user());
        abort_if($machine->site_id === null || ($site !== null && (int) $machine->site_id !== $site), 403);
    }
}
