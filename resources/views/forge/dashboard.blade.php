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
        </div>

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
