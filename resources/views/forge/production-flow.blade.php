<x-app-layout>
    @include('access-control._style')
    <style>
        .production-flow-root,.production-flow-layout,.production-flow-grid,.production-flow-step { min-width:0; max-width:100%; }
        html:has(.production-flow-root),body:has(.production-flow-root) { overflow-x:clip; }
        .production-flow-root { overflow-x:clip; }
        .production-flow-root > * { min-width:0; max-width:100%; }
        .production-flow-layout { display:grid; grid-template-columns:minmax(0,240px) minmax(0,1fr); gap:1.25rem; align-items:start; }
        .production-flow-rail { position:sticky; top:1rem; }
        .production-flow-nav { display:grid; gap:.35rem; }
        .production-flow-nav a { display:flex; gap:.7rem; align-items:center; min-height:42px; padding:.55rem .65rem; border-radius:.7rem; color:#475569; text-decoration:none; transition:background-color .18s ease,color .18s ease; }
        .production-flow-nav a:hover,.production-flow-nav a:focus-visible { background:#eef2ff; color:#3730a3; outline:2px solid #818cf8; outline-offset:1px; }
        .production-flow-nav strong { display:grid; place-items:center; width:27px; height:27px; border-radius:8px; background:#eef2ff; color:#4338ca; font-size:.72rem; }
        .production-flow-grid { display:grid; gap:.9rem; }
        .production-flow-step { scroll-margin-top:1rem; border:1px solid #e2e8f0; border-radius:1rem; background:#fff; padding:1.1rem; }
        .production-flow-step:target { border-color:#818cf8; box-shadow:0 0 0 3px rgb(99 102 241 / .12); }
        .production-flow-meta { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; color:#64748b; font-size:.78rem; }
        .production-flow-step h2 { margin:.35rem 0 .45rem; font-size:1.05rem; font-weight:700; color:#0f172a; }
        .production-flow-facts { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.65rem; margin-top:.9rem; }
        .production-flow-facts > div { border-radius:.7rem; background:#f8fafc; padding:.7rem; }
        .production-flow-facts small { display:block; margin-bottom:.25rem; color:#64748b; font-size:.68rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .production-flow-facts span { color:#334155; font-size:1rem; line-height:1.5; }
        .production-flow-action { display:inline-flex; align-items:center; justify-content:center; min-height:40px; margin-top:.9rem; border-radius:.65rem; background:#4338ca; padding:.55rem .9rem; color:#fff; font-size:.82rem; font-weight:700; text-decoration:none; }
        .production-flow-action:hover { background:#3730a3; }
        .production-flow-action:focus-visible { outline:3px solid #a5b4fc; outline-offset:2px; }
        .production-flow-action { white-space:normal; text-align:center; }
        .production-flow-next .production-flow-action { margin-top:0; }
        .production-flow-filter { width:min(100%,34rem); min-width:0; flex:1 1 18rem; }
        .production-flow-filter select { width:100%; min-width:0; max-width:100%; min-height:44px; text-overflow:ellipsis; }
        .production-flow-overview { border-color:#cbd5e1; }
        .production-flow-progress-track { height:.65rem; overflow:hidden; border-radius:999px; background:#e2e8f0; }
        .production-flow-progress-value { height:100%; border-radius:inherit; background:#147d4f; transition:width .25s ease; }
        .production-flow-next { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; border-radius:.8rem; background:#f8fafc; padding:1rem; }
        .production-flow-next h3 { margin-top:.15rem; font-size:1rem; font-weight:750; color:#0f172a; }
        .production-flow-nav a[aria-current="step"] { background:#eef2ff; color:#3730a3; }
        .production-flow-nav a[data-state="complete"] strong { background:#dcfce7; color:#166534; }
        .production-flow-nav a[data-state="current"] strong { background:#4338ca; color:white; }
        .production-flow-state { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.2rem .55rem; font-size:.75rem; font-weight:700; }
        .production-flow-state.complete { background:#dcfce7; color:#166534; }
        .production-flow-state.current { background:#dbeafe; color:#1d4ed8; }
        .production-flow-state.upcoming { background:#f1f5f9; color:#475569; }
        .production-flow-line { display:flex; flex-wrap:wrap; align-items:center; gap:.35rem; }
        .production-flow-step p,.production-flow-facts span { overflow-wrap:anywhere; }
        .production-flow-step > p { font-size:1rem; line-height:1.55; }
        @media (max-width:900px) { .production-flow-layout { grid-template-columns:minmax(0,1fr); } .production-flow-rail { position:static; min-width:0; width:100%; } .production-flow-nav { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); min-width:0; max-width:100%; overflow:visible; padding-bottom:.25rem; } .production-flow-nav a { min-width:0; flex:auto; } }
        @media (max-width:600px) { .production-flow-facts { grid-template-columns:1fr; } .production-flow-next { align-items:stretch; flex-direction:column; } .production-flow-next .production-flow-action { width:100%; } .production-flow-nav { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); overflow:visible; } .production-flow-nav a { min-width:0; flex:auto; } }
        @media (prefers-reduced-motion:reduce) { .production-flow-nav a { transition:none; } }
    </style>

    <div class="access-shell production-flow-root max-w-7xl mx-auto space-y-5">
        <header class="access-top p-5">
            <div class="text-sm access-muted">Forge / Production Flow</div>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">Production Flow · 6 Steps</h1>
                    <p class="access-muted mt-1 max-w-3xl">Track live ERP progress from sales demand through RM/PM issue, one PCM mix, filling and sealing, QC, physical bundle count with BOM reconciliation, bin putaway, and dispatch.</p>
                </div>
                @if ($canWorkOrders)
                    <a class="production-flow-action" href="{{ route('forge.workorders.index') }}">Open work orders <span aria-hidden="true" class="ml-2">→</span></a>
                @endif
            </div>
        </header>

        <form class="access-card p-4 flex flex-wrap gap-3 items-end" method="get" action="{{ route('forge.production-flow') }}">
            <label class="production-flow-filter text-sm font-semibold">Sales order to track<br><select class="access-input mt-1" name="order"><option value="">Process guide · select an order</option>@foreach($orders as $candidate)<option value="{{ $candidate->id }}" @selected($order?->id === $candidate->id)>{{ $candidate->order_number }} · {{ $candidate->customer_name }} · {{ $candidate->status }}</option>@endforeach</select></label>
            <button class="access-btn access-btn-primary">Show live flow</button>
            @if($order)<a class="access-btn" href="{{ route('flow.orders.show', $order) }}">Sales order details</a>@endif
        </form>

        @if($order)
        <section class="access-card production-flow-overview p-5" aria-labelledby="flow-progress-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="access-muted text-xs font-semibold uppercase tracking-wide">Live order journey</div>
                    <h2 id="flow-progress-title" class="font-bold text-lg mt-1">Production progress</h2>
                </div>
                <strong class="text-sm">{{ $completedSteps }} of 6 steps complete · {{ $progressPercent }}%</strong>
            </div>
            <div class="production-flow-progress-track mt-3" role="progressbar" aria-label="Production flow completion" aria-valuemin="0" aria-valuemax="6" aria-valuenow="{{ $completedSteps }}">
                <div class="production-flow-progress-value" style="width:{{ $progressPercent }}%"></div>
            </div>
            @if($currentStepIndex !== false)
                @php($nextStep = $steps[$currentStepIndex])
                <div class="production-flow-next">
                    <div class="min-w-0">
                        <div class="access-muted text-xs font-semibold uppercase tracking-wide">Next up · Step {{ str_pad((string) ($currentStepIndex + 1), 2, '0', STR_PAD_LEFT) }} · {{ $nextStep['owner'] }}</div>
                        <h3>{{ $nextStep['title'] }}</h3>
                        <p class="text-sm mt-1">{{ $nextStep['live_blocker'] }}</p>
                    </div>
                    @if($nextStep['url'])
                        <a class="production-flow-action mt-0 shrink-0" href="{{ $nextStep['url'] }}">{{ $nextStep['action'] }} <span aria-hidden="true" class="ml-2">→</span></a>
                    @else
                        <span class="access-muted text-sm">Assigned to {{ $nextStep['owner'] }}</span>
                    @endif
                </div>
            @else
                <div class="production-flow-next" role="status">
                    <div><div class="production-flow-state complete">Production flow complete</div><p class="text-sm mt-2">All six production steps are complete. Continue with the shipment and finance handoff below.</p></div>
                </div>
            @endif
        </section>
        <section class="access-card p-5">
            <div class="text-sm access-muted">Sales demand · {{ $order->order_number }} · site #{{ $order->site_id }} · FG warehouse #{{ $order->fg_warehouse_id }}</div>
            <h2 class="font-bold text-lg mt-1">{{ $order->customer_name }} · {{ $order->status }}</h2>
            <div class="grid sm:grid-cols-4 gap-3 mt-4 text-sm"><div><strong>{{ $ordered }}</strong><br>Ordered</div><div><strong>{{ $allocated }}</strong><br>Allocated</div><div><strong>{{ max(0, $ordered-$allocated) }}</strong><br>Shortage</div><div><strong>{{ $accepted }}</strong><br>FG batches posted</div></div>
            <div class="overflow-x-auto max-w-full mt-4"><table class="access-table"><thead><tr><th>FG</th><th>Ordered</th><th>Allocated</th><th>Production plan</th><th>Work order / batch</th></tr></thead><tbody>@foreach($order->lines as $line)<tr><td>{{ $line->finishedGood?->name }} · SKU #{{ $line->inventory_fg_item_id }}</td><td>{{ $line->qty_ordered }} {{ $line->uom }}</td><td>{{ $line->qty_allocated }}</td><td>@foreach($plans->where('source_order_line_id',$line->id) as $plan)<a href="{{ route('forge.plans.show', $plan) }}">{{ $plan->plan_number }} ({{ $plan->target_qty }})</a><br>@endforeach</td><td>@foreach($plans->where('source_order_line_id',$line->id) as $plan)@foreach($plan->workOrders as $linkedWo)<a href="{{ route('forge.workorders.show',$linkedWo) }}">{{ $linkedWo->wo_number }}</a> · {{ $linkedWo->status }} · {{ $linkedWo->batch?->qty ?? 0 }} FG<br>@endforeach @endforeach</td></tr>@endforeach</tbody></table></div>
        </section>
        @endif

        <div class="grid sm:grid-cols-3 gap-3">
            <section class="access-card p-4"><div class="access-muted text-xs font-semibold uppercase tracking-wide">Plan → Issue</div><p class="mt-2 text-sm">Select an approved recipe, exact SKU, and warehouse, then reserve and issue RM/PM.</p></section>
            <section class="access-card p-4"><div class="access-muted text-xs font-semibold uppercase tracking-wide">Make → QC</div><p class="mt-2 text-sm">Record mixing, filling, sealing, and leak/pressure test results against the batch.</p></section>
            <section class="access-card p-4"><div class="access-muted text-xs font-semibold uppercase tracking-wide">FG → Dispatch</div><p class="mt-2 text-sm">Put released FG away in a bin and deduct stock when the shipment is released.</p></section>
        </div>

        @unless($order)
            <div class="access-card p-4" role="status"><strong>Select a sales order to see live progress.</strong><p class="access-muted text-sm mt-1">Choose an order to track live progress and the next action for each role.</p></div>
        @endunless

        <div class="production-flow-layout">
            <aside class="production-flow-rail access-card p-4" aria-label="Production process navigation">
                <div class="mb-3">
                    <h2 class="font-bold">Process steps</h2>
                    <p class="access-muted text-xs mt-1">@if($order) Status and next action for this sales order. @else Select a step to review its inputs and control. @endif</p>
                </div>
                <nav class="production-flow-nav">
                    @foreach ($steps as $index => $step)
                        <a href="#production-step-{{ $index + 1 }}" data-state="{{ $step['progress_state'] }}" @if($order && $step['progress_state'] === 'current') aria-current="step" @endif><strong>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</strong><span class="text-sm font-medium">{{ $step['title'] }}</span>@if($order)<span class="sr-only">{{ ucfirst($step['progress_state']) }}</span>@endif</a>
                    @endforeach
                    <a href="#dispatch-handoff" data-state="{{ $handoffProgressState }}" @if($order && $handoffProgressState === 'current') aria-current="step" @endif><strong>07</strong><span class="text-sm font-medium">Dispatch &amp; Finance Handoff</span>@if($order)<span class="sr-only">{{ ucfirst($handoffProgressState) }}</span>@endif</a>
                </nav>
            </aside>

            <main class="production-flow-grid" aria-label="Production process guide">
                @foreach ($steps as $index => $step)
                    <section class="production-flow-step" id="production-step-{{ $index + 1 }}">
                        <div class="production-flow-meta"><span>STEP {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><span aria-hidden="true">·</span><span>{{ $step['owner'] }}</span>@if($order)<span class="production-flow-state {{ $step['progress_state'] }}">{{ ucfirst($step['progress_state']) }}</span>@endif</div>
                        <h2>{{ $step['title'] }}</h2>
                        @if($order)<div class="production-flow-line mt-2"><span class="access-chip">{{ $step['live_status'] }}</span><strong class="text-sm">{{ $step['live_qty'] }}</strong></div><p class="text-sm mt-2"><strong>{{ $step['progress_state'] === 'complete' ? 'Result:' : 'Next:' }}</strong> {{ $step['progress_state'] === 'complete' ? $step['live_qty'] : $step['live_blocker'] }}</p>@endif
                        <p class="access-muted text-sm leading-relaxed">{{ $step['description'] }}</p>
                        <div class="production-flow-facts">
                            <div><small>Required input</small><span>{{ $step['input'] }}</span></div>
                            <div><small>ERP control</small><span>{{ $step['control'] }}</span></div>
                            <div><small>Step outcome</small><span>{{ $step['outcome'] }}</span></div>
                        </div>
                        @if ($step['url'])
                    <a class="production-flow-action" href="{{ $step['url'] }}">{{ $order && $step['progress_state'] === 'complete' ? 'Review records' : $step['action'] }} <span aria-hidden="true" class="ml-2">→</span></a>
                        @else
                            <p class="access-muted text-sm mt-3">{{ $step['progress_state'] === 'complete' ? 'Step complete.' : ($order ? 'This action is assigned to '.$step['owner'].'.' : 'The action is available to users with the matching role permission.') }}</p>
                        @endif
                    </section>
                @endforeach

                <section id="dispatch-handoff" class="access-card p-4 scroll-mt-4">
                    <div class="production-flow-meta"><span>HANDOFF</span><span aria-hidden="true">·</span><span>Logistics / Finance</span>@if($order)<span class="production-flow-state {{ $handoffProgressState }}">{{ ucfirst($handoffProgressState) }}</span>@endif</div>
                    <h2 class="font-bold mt-2">Dispatch &amp; Finance Handoff</h2>
                    <p class="access-muted text-sm mt-2">Picking and line-level packing open after the full order is allocated. Load sealed handling units onto a shipment. Shipment release deducts stock once for the exact FG SKU, site, and warehouse, then creates a Finance review task.</p>
                    @if($order)
                        <div class="grid sm:grid-cols-3 gap-3 mt-4 text-sm">
                            <div class="rounded-lg bg-slate-50 p-3"><span class="access-muted block text-xs uppercase tracking-wide">Order status</span><strong>{{ str($order->status)->headline() }}</strong></div>
                            <div class="rounded-lg bg-slate-50 p-3"><span class="access-muted block text-xs uppercase tracking-wide">Picked</span><strong>{{ $order->lines->sum('qty_picked') }} / {{ $ordered }}</strong></div>
                            <div class="rounded-lg bg-slate-50 p-3"><span class="access-muted block text-xs uppercase tracking-wide">Packed</span><strong>{{ $order->lines->sum('qty_packed') }} / {{ $ordered }}</strong></div>
                        </div>
                        @if($order->status === 'allocated')<a class="production-flow-action" href="{{ route('flow.waves.index') }}">Create pick wave →</a>@elseif(in_array($order->status,['picked','packing']))<a class="production-flow-action" href="{{ route('flow.packing.index') }}">Pack order →</a>@elseif(in_array($order->status,['packed','dispatch_planned']))<a class="production-flow-action" href="{{ route('flow.dispatch.index') }}">Dispatch →</a>@elseif(in_array($order->status,['in_transit','pod_received','closed']))<p class="access-muted text-sm mt-3">Shipment released. Stock was deducted once and a Finance review task was created.</p>@endif
                    @endif
                </section>
            </main>
        </div>
    </div>
</x-app-layout>
