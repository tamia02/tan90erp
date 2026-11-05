<x-app-layout>
    @include('access-control._style')
    <style>
        .production-step-nav{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.5rem}
        .production-step-nav a{display:block;min-width:0;padding:.75rem;border:1px solid #dfe7e2;border-radius:.75rem;text-decoration:none;color:#334155;background:#fff;overflow-wrap:anywhere}
        .production-step-nav a[aria-current=step]{border-color:#147d4f;background:#e9f7ee;color:#145534;font-weight:700}
        .production-step-nav a:hover,.production-step-nav a:focus-visible{border-color:#147d4f;outline:2px solid #9fd9b7}
        .production-step-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:.75rem}
        .production-field{display:grid;gap:.3rem;font-size:.82rem;font-weight:600;color:#475569}
        .production-field .access-input{color:#17211b;font-weight:400}
        .production-panel{display:grid;gap:1rem}
        @media(max-width:950px){.production-step-nav{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:520px){.production-step-nav{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style>
    @php($stepLabels = ['materials' => 'Start · RM & PM Issue', 'mixing' => 'PCM Making', 'filling-sealing' => 'Production · Filling & Sealing', 'quality' => 'QC · Pressure & Leak Test', 'consumption-fg' => 'Bundle · Consumption & FG', 'finished-goods' => 'FG Store · Bin Putaway'])
    @php($stepNumber = array_search($moduleStep, array_keys($stepLabels), true) + 1)
    <div class="access-shell max-w-7xl mx-auto space-y-5" id="production-step-workspace">
        <header class="access-top p-5 sm:p-6">
            <div class="flex flex-wrap justify-between items-start gap-3">
                <div class="min-w-0">
                    <div class="text-sm access-muted">Production / Step {{ str_pad($stepNumber, 2, '0', STR_PAD_LEFT) }} of 06</div>
                    <h1 class="text-2xl sm:text-3xl font-bold mt-1">{{ $stepLabels[$moduleStep] }}</h1>
                    <p class="access-muted text-sm mt-2">{{ $wo->finishedGood?->name }} · {{ $wo->wo_number }} · {{ $wo->target_qty }} {{ $wo->uom }}</p>
                </div>
                <span class="access-chip">{{ str($wo->status)->replace('_', ' ')->headline() }}</span>
            </div>
        </header>
        @if(session('status'))<div class="access-card p-3 text-sm" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

        <nav class="production-step-nav" aria-label="Production work order steps">
            @foreach($stepLabels as $key => $label)
                <a href="{{ route('forge.modules.workorder', ['step' => $key, 'workOrder' => $wo]) }}" @if($key === $moduleStep) aria-current="step" @endif><span class="block text-xs access-muted">Step {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>{{ $label }}</a>
            @endforeach
        </nav>

        <section class="production-step-grid" aria-label="Linked production records">
            <div class="access-card p-4"><span class="access-muted text-xs">Production demand</span><strong class="block mt-1">{{ $wo->productionPlan?->plan_number ?? 'Manual work order' }}</strong>@if($wo->productionPlan?->sourceOrderLine?->order)<a class="underline text-sm" href="{{ route('flow.orders.show', $wo->productionPlan->sourceOrderLine->order) }}">{{ $wo->productionPlan->sourceOrderLine->order->order_number }}</a>@endif</div>
            <div class="access-card p-4"><span class="access-muted text-xs">Approved definition</span><strong class="block mt-1">{{ $wo->recipe?->code ?? $wo->bom?->code ?? 'Not selected' }}</strong><span class="text-xs access-muted">{{ $bomItems->count() }} inventory recipe material(s)</span></div>
            <div class="access-card p-4"><span class="access-muted text-xs">FG stock SKU and site</span><strong class="block mt-1">{{ $wo->inventoryFgItem?->safeDisplayName() ?? 'Not mapped' }}</strong><span class="text-xs access-muted">Site #{{ $wo->site_id }} · RM WH #{{ $wo->rm_warehouse_id }} · FG WH #{{ $wo->fg_warehouse_id }}</span></div>
            <div class="access-card p-4"><span class="access-muted text-xs">QC and FG</span><strong class="block mt-1">{{ $wo->finalQcResult?->accepted_qty ?? 0 }} {{ $wo->uom }} accepted</strong><span class="text-xs access-muted">{{ $wo->batch?->batch_number ?? 'Batch pending' }} · {{ $wo->batch?->inventoryLot?->bin ?? 'Bin pending' }}</span></div>
        </section>

        <main class="production-panel">
            @include('forge.work-orders.steps.'.$moduleStep)
        </main>

        <footer class="access-card p-4 flex flex-wrap gap-2 items-center justify-between">
            <a class="access-btn" href="{{ route('forge.workorders.show', $wo) }}">Complete work-order trace</a>
            @if($stepNumber < 6)
                @php($nextStep = array_keys($stepLabels)[$stepNumber])
                <a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => $nextStep, 'workOrder' => $wo]) }}">Next: {{ $stepLabels[$nextStep] }} →</a>
            @else
                <a class="access-btn" href="{{ route('flow.inventory.index', ['work_order' => $wo->id]) }}">FG inventory ledger →</a>
            @endif
        </footer>
    </div>
</x-app-layout>
