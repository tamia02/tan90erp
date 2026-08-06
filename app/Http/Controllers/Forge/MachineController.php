<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Machine;
use App\Models\Forge\MachineDowntimeEvent;
use App\Services\Access\AccessControlService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MachineController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.view'), 403);

        $machines = Machine::with(['workCenter', 'downtimeEvents' => fn ($q) => $q->whereNull('ended_at')])->orderBy('name')->get();

        return view('forge.machines.index', [
            'machines' => $machines,
            'recentDowntime' => MachineDowntimeEvent::with('machine')->latest('started_at')->limit(20)->get(),
        ]);
    }

    public function startDowntime(Request $request, Machine $machine)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);

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
        abort_unless($downtime->ended_at === null, 422, 'Downtime event already closed.');

        $data = $request->validate([
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
        ]);

        $downtime->update($data + ['ended_at' => now()]);
        $downtime->machine->update(['state' => 'idle']);

        AuditLogger::log('Machine downtime closed', $downtime->machine->name, $downtime);

        return back()->with('status', "Downtime closed for {$downtime->machine->name} ({$downtime->durationMinutes()} min).");
    }

    public function setState(Request $request, Machine $machine)
    {
        abort_unless($this->access->can($request->user(), 'forge.machine.downtime'), 403);

        $data = $request->validate(['state' => ['required', 'in:idle,setup,running,down,maintenance']]);
        $machine->update($data);

        AuditLogger::log('Machine state changed', $machine->name.' -> '.$data['state'], $machine);

        return back()->with('status', "{$machine->name} set to {$data['state']}.");
    }
}
