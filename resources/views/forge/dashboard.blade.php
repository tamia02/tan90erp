<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge</div>
            <h1 class="text-2xl font-bold">Manufacturing Command Centre</h1>
        </div>

        <div class="access-grid">
            <div class="access-card p-4">
                <div class="access-muted text-sm">Open Quality Holds</div>
                <div class="workspace-metric">{{ $openHolds }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Machines Down</div>
                <div class="workspace-metric">{{ $openDowntime }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Open Deviations / CAPA</div>
                <div class="workspace-metric">{{ $openDeviations }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Work Orders (all statuses)</div>
                <div class="workspace-metric">{{ $statusCounts->sum() }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Wastage logged today</div>
                <div class="workspace-metric">{{ $wastageTodayCount }}</div>
                <div class="access-muted text-xs">{{ $wastageTodayByUnit->isEmpty() ? 'No wastage logged' : $wastageTodayByUnit->values()->join(' · ') }}</div>
            </div>
        </div>

        @if ($canViewMachineTime)
        <div class="access-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="font-bold">Machine working time today</h2>
                    <p class="access-muted text-sm">Recorded job time minus breakdowns and other logged stops during that time.</p>
                </div>
                <a class="access-btn" href="{{ route('forge.machines.index') }}">Machine log</a>
            </div>
            <div class="access-grid mb-4">
                @foreach (['scheduled_seconds' => 'Recorded job time', 'stopped_seconds' => 'Breakdown / stop time', 'working_seconds' => 'Actual working time'] as $key => $label)
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <div class="access-muted text-sm">{{ $label }}</div>
                        <div class="font-bold text-lg">{{ intdiv($machineTotals[$key], 3600) }}h {{ intdiv($machineTotals[$key] % 3600, 60) }}m</div>
                    </div>
                @endforeach
            </div>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Machine</th><th>State</th><th>Job time</th><th>Stops</th><th>Working time</th></tr></thead>
                    <tbody>
                        @forelse ($machineTimes as $row)
                            <tr>
                                <td><strong>{{ $row['machine']->name }}</strong><div class="access-muted text-xs">{{ $row['machine']->code }}</div></td>
                                <td>{{ str($row['machine']->state)->headline() }}</td>
                                @foreach (['scheduled_seconds', 'stopped_seconds', 'working_seconds'] as $key)
                                    <td>{{ intdiv($row['time'][$key], 3600) }}h {{ intdiv($row['time'][$key] % 3600, 60) }}m</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No machines configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="access-muted text-xs mt-3">Tracking starts when a job card is started with a machine selected or when a machine is selected on a timed filling/sealing record. Earlier job cards have no reliable pause history, so they are not included.</p>
        </div>
        @endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Work Orders by Status</h2>
            <div class="flex flex-wrap gap-2">
                @forelse ($statusCounts as $status => $count)
                    <span class="access-chip">{{ str($status)->replace('_', ' ')->headline() }}: {{ $count }}</span>
                @empty
                    <p class="access-muted text-sm">No work orders yet.</p>
                @endforelse
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">In Progress / Reconciliation</h2>
                <div class="space-y-2">
                    @forelse ($inProgress as $wo)
                        <a href="{{ route('forge.workorders.show', $wo) }}" class="block border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            <strong>{{ $wo->wo_number }}</strong> — {{ $wo->finishedGood?->name }}
                            <span class="access-chip ml-2">{{ str($wo->status)->replace('_', ' ')->headline() }}</span>
                        </a>
                    @empty
                        <p class="access-muted text-sm">Nothing in progress.</p>
                    @endforelse
                </div>
            </section>
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Awaiting Final QC</h2>
                <div class="space-y-2">
                    @forelse ($finalQcPending as $wo)
                        <a href="{{ route('forge.final-qc.index') }}" class="block border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            <strong>{{ $wo->wo_number }}</strong> — {{ $wo->finishedGood?->name }}
                            <span class="access-muted">good qty {{ $wo->good_qty }}</span>
                        </a>
                    @empty
                        <p class="access-muted text-sm">Nothing awaiting final QC.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Jump to</h2>
            <div class="flex flex-wrap gap-2">
                <a class="access-btn" href="{{ route('forge.plans.index') }}">Production Plans</a>
                <a class="access-btn" href="{{ route('forge.workorders.index') }}">Work Orders</a>
                <a class="access-btn" href="{{ route('forge.machines.index') }}">Machines & OEE</a>
                <a class="access-btn" href="{{ route('forge.wastage.index') }}">Wastage & Scrap</a>
                <a class="access-btn" href="{{ route('forge.quality-holds.index') }}">In-Process Quality</a>
                <a class="access-btn" href="{{ route('forge.final-qc.index') }}">Final Quality & FG Release</a>
                <a class="access-btn" href="{{ route('forge.deviations.index') }}">Deviation, Rework & CAPA</a>
                <a class="access-btn" href="{{ route('forge.batches.index') }}">Batch Genealogy</a>
            </div>
        </div>
    </div>
</x-app-layout>
