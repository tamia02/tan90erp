<x-app-layout>
    @include('access-control._style')
    <style>
        .forge-module-grid { display:grid; grid-template-columns:minmax(0, 1fr) minmax(250px, .72fr); gap:1rem; }
        .forge-module-steps { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.55rem; }
        .forge-module-step { min-width:0; display:flex; flex-direction:column; gap:.4rem; min-height:90px; padding:.75rem; border:1px solid #dfe7e2; border-radius:.8rem; color:#334155; text-decoration:none; background:#fff; }
        .forge-module-step:hover,.forge-module-step:focus-visible { border-color:#6366f1; background:#f5f5ff; outline:2px solid #a5b4fc; outline-offset:1px; }
        .forge-module-step[aria-current="step"] { border-color:#4338ca; background:#eef2ff; color:#312e81; }
        .forge-module-step-number { display:grid; place-items:center; width:1.8rem; height:1.8rem; border-radius:.55rem; background:#eef2ff; color:#4338ca; font-weight:700; font-size:.75rem; }
        .forge-module-list { padding-left:1.1rem; list-style:disc; }
        .forge-module-list li + li { margin-top:.55rem; }
        @media (max-width: 900px) { .forge-module-steps { grid-template-columns:repeat(3,minmax(0,1fr)); } }
        @media (max-width: 720px) { .forge-module-grid { grid-template-columns:minmax(0,1fr); } }
        @media (max-width: 480px) { .forge-module-steps { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    </style>

    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <header class="access-top p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-sm access-muted">Production Modules / Step {{ $module['number'] }} of 06</div>
                    <h1 class="text-2xl sm:text-3xl font-bold mt-1">{{ $module['title'] }}</h1>
                    <p class="access-muted mt-2 max-w-3xl">{{ $module['purpose'] }}</p>
                    <p class="text-sm mt-3"><strong>Who works here:</strong> {{ $module['owner'] }}</p>
                </div>
                <a class="access-btn" href="{{ route('forge.production-flow') }}">View full production flow</a>
            </div>
        </header>

        <nav class="access-card p-3 sm:p-4" aria-label="Production steps">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="font-bold">The six production steps</h2>
                <span class="access-muted text-xs">Select a step, then open a work order to complete its action.</span>
            </div>
            <div class="forge-module-steps">
                @foreach($modules as $item)
                    <a class="forge-module-step" href="{{ route('forge.modules.show', ['step' => $item['key']]) }}" @if($item['key'] === $step) aria-current="step" @endif>
                        <span class="forge-module-step-number">{{ $item['number'] }}</span>
                        <span class="text-sm font-semibold leading-snug">{{ $item['title'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        <section class="access-card p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3" aria-label="Current step work">
            <div>
                <strong>{{ $workOrders->count() }} work order(s) at this step</strong>
                <p class="access-muted text-sm mt-1">{{ $workOrders->isEmpty() ? 'The active queue is clear. Open the last finished run to see the recorded steps, stock movements and bin trace.' : 'Select an order below to complete or inspect this step.' }}</p>
            </div>
            @if($recentCompleted)
                <a class="access-btn" href="{{ route('forge.modules.workorder', ['step' => $step, 'workOrder' => $recentCompleted]) }}">Review completed example {{ $recentCompleted->wo_number }}</a>
            @elseif($step === 'materials' && $canViewPlans)
                <a class="access-btn access-btn-primary" href="{{ route('forge.plans.index') }}">Create a production plan</a>
            @endif
        </section>

        <div class="forge-module-grid">
            <section class="access-card p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-lg">What to record</h2>
                        <p class="access-muted text-sm mt-1">Capture these details on the linked work order.</p>
                    </div>
                    <span class="access-chip">Step {{ $module['number'] }}</span>
                </div>
                <ul class="forge-module-list mt-4 text-sm">
                    @foreach($module['fields'] as $field)
                        <li>{{ $field }}</li>
                    @endforeach
                </ul>
            </section>

            <section class="access-card p-5 sm:p-6">
                <h2 class="font-bold text-lg">What ERP checks</h2>
                <p class="access-muted text-sm mt-1">These checks prevent stock and traceability errors.</p>
                <ul class="forge-module-list mt-4 text-sm">
                    @foreach($module['checks'] as $check)
                        <li>{{ $check }}</li>
                    @endforeach
                </ul>
            </section>
        </div>

        <section class="access-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="font-bold text-lg">When this step is done</h2>
                    <p class="access-muted text-sm mt-1">{{ $module['outcome'] }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a class="access-btn access-btn-primary" href="{{ $workspaceUrl }}">{{ $module['workspace_label'] }} <span aria-hidden="true">→</span></a>
                    @if($step === 'materials' && $canCreateWorkOrder)
                        <a class="access-btn" href="{{ route('forge.workorders.create') }}">Create production work order</a>
                    @endif
                    @if($step === 'materials' && $canViewPlans)
                        <a class="access-btn" href="{{ route('forge.plans.index') }}">Open production plans</a>
                    @endif
                </div>
            </div>
        </section>

        <section class="access-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-bold text-lg">Work orders for this step</h2>
                    <p class="access-muted text-sm mt-1">Choose an order to complete this step. Its stock, QC and batch references stay linked.</p>
                </div>
                <span class="access-chip">{{ $workOrders->count() }} shown</span>
            </div>

            <div class="overflow-x-auto">
                <table class="access-table">
                    <thead><tr><th>Work order</th><th>Finished good</th><th>Target</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse($workOrders as $workOrder)
                            <tr>
                                <td><strong>{{ $workOrder->wo_number }}</strong></td>
                                <td>{{ $workOrder->finishedGood?->name ?? 'Finished good not set' }}</td>
                                <td>{{ $workOrder->target_qty }} {{ $workOrder->uom }}</td>
                                <td><span class="access-chip">{{ str($workOrder->status)->replace('_', ' ')->headline() }}</span></td>
                                <td>
                                    <a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => $step, 'workOrder' => $workOrder]) }}">Open step {{ $module['number'] }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No work orders are waiting at this step. Orders appear here when they reach this stage.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
