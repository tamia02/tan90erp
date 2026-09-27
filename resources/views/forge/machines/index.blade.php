<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Machines</div>
            <h1 class="text-2xl font-bold">Machines, OEE & Downtime</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-grid">
            @forelse ($machines as $machine)
                <div class="access-card p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <strong>{{ $machine->name }}</strong>
                        <span class="access-chip">{{ str($machine->state)->headline() }}</span>
                    </div>
                    <div class="access-muted text-sm">{{ $machine->code }} · {{ $machine->workCenter?->name ?? 'No work centre' }} · {{ $machine->plant }}</div>

                    @if ($machine->state !== 'down')
                        <details>
                            <summary class="access-btn" style="display:inline-flex;cursor:pointer">Log Downtime</summary>
                            <form class="space-y-2 mt-2" method="post" action="{{ route('forge.machines.downtime', $machine) }}">
                                @csrf
                                <select class="access-input" name="category" required>
                                    <option value="breakdown">Breakdown</option>
                                    <option value="planned_stop">Planned Stop</option>
                                    <option value="changeover">Changeover</option>
                                    <option value="material_shortage">Material Shortage</option>
                                    <option value="quality_hold">Quality Hold</option>
                                </select>
                                <select class="access-input" name="severity" required>
                                    <option value="minor">Minor</option>
                                    <option value="major">Major</option>
                                    <option value="critical">Critical</option>
                                </select>
                                <textarea class="access-input" name="observation" placeholder="Observation"></textarea>
                                <button class="access-btn access-btn-primary">Log Downtime</button>
                            </form>
                        </details>
                    @else
                        @php($open = $machine->downtimeEvents->first())
                        @if ($open)
                            <form class="space-y-2" method="post" action="{{ route('forge.machines.downtime.close', $open) }}">
                                @csrf
                                <input class="access-input" name="root_cause" placeholder="Root cause">
                                <input class="access-input" name="corrective_action" placeholder="Corrective action">
                                <button class="access-btn access-btn-primary">Close Downtime</button>
                            </form>
                        @endif
                    @endif

                    <form method="post" action="{{ route('forge.machines.state', $machine) }}" class="flex gap-2 items-center">
                        @csrf
                        <select class="access-input" name="state">
                            @foreach (['idle', 'setup', 'running', 'maintenance'] as $state)
                                <option value="{{ $state }}" @selected($machine->state === $state)>{{ ucfirst($state) }}</option>
                            @endforeach
                        </select>
                        <button class="access-btn">Set</button>
                    </form>
                </div>
            @empty
                <p class="access-muted">No machines configured yet.</p>
            @endforelse
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Recent Downtime</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Machine</th><th>Category</th><th>Severity</th><th>Started</th><th>Ended</th><th>Duration</th></tr></thead>
                    <tbody>
                        @forelse ($recentDowntime as $event)
                            <tr>
                                <td>{{ $event->machine?->name }}</td>
                                <td>{{ str($event->category)->headline() }}</td>
                                <td><span class="access-chip">{{ $event->severity }}</span></td>
                                <td>{{ $event->started_at->format('d M, H:i') }}</td>
                                <td>{{ $event->ended_at?->format('d M, H:i') ?? 'Open' }}</td>
                                <td>{{ $event->durationMinutes() !== null ? $event->durationMinutes().' min' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No downtime events recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
