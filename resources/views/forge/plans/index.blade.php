<x-app-layout>
    @include('access-control._style')
    <style>
        .planning-path { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem; }
        .planning-path > div { border:1px solid #dfe7e2; border-radius:.75rem; padding:1rem; background:#fff; }
        .planning-step { color:#4338ca; font-size:.75rem; font-weight:800; letter-spacing:.08em; }
        @media(max-width:720px) { .planning-path { grid-template-columns:1fr; } }
    </style>
    <div class="access-shell max-w-7xl mx-auto space-y-5" data-listing-ignore>
        <div class="access-top p-5 sm:p-6">
            <div class="text-sm access-muted">Production / Planning</div>
            <h1 class="text-2xl sm:text-3xl font-bold">Production Plans</h1>
            @include('forge._exports', ['permission'=>'forge.plan.export', 'exportRoute'=>'forge.plans.export'])
            <p class="access-muted mt-2">Turn sales demand or a planned quantity into an approved shop-floor work order.</p>
            <div class="flex flex-wrap gap-2 mt-3">
                <a class="access-btn" href="{{ route('forge.production-flow') }}">View 6-step flow</a>
                <a class="access-btn" href="{{ route('forge.mappings.index') }}">Product & site mappings</a>
            </div>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700" role="alert"><strong>Plan not saved.</strong> {{ $errors->first() }}</div>@endif

        <section class="planning-path" aria-label="Planning process">
            <div><span class="planning-step">01 · DEMAND</span><h2 class="font-bold mt-1">Choose product & quantity</h2><p class="access-muted text-sm mt-1">Sales shortages make linked plans automatically. Use the form below for a manual production requirement.</p></div>
            <div><span class="planning-step">02 · APPROVAL</span><h2 class="font-bold mt-1">Approve the plan</h2><p class="access-muted text-sm mt-1">The approved product, recipe, inventory SKU, site and warehouses must be ready before freezing.</p></div>
            <div><span class="planning-step">03 · EXECUTION</span><h2 class="font-bold mt-1">Create a work order</h2><p class="access-muted text-sm mt-1">The work order issues RM/PM and follows mixing, production, QC and FG posting.</p></div>
        </section>

        @if($canCreate)
        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.plans.store') }}">
            @csrf
            <h2 class="font-bold text-lg">Create a manual plan</h2>
            <p class="access-muted text-sm">Use this when the quantity is not already covered by a sales-order plan. {{ $finishedGoods->count() }} approved product {{ $finishedGoods->count() === 1 ? 'master' : 'masters' }} available for planning.</p>
            <div class="access-grid">
                <label class="block text-sm font-semibold">Finished good
                    <select class="access-input mt-1 w-full" name="finished_good_id" id="plan-product" required>
                        <option value="">Select finished good</option>
                        @foreach ($finishedGoods as $fg)
                            <option value="{{ $fg->id }}" data-uom="{{ $fg->uom }}" @selected(old('finished_good_id') == $fg->id)>{{ $fg->code }} — {{ $fg->name }}</option>
                        @endforeach
                    </select>
                    <span class="access-muted block text-xs mt-1">Only approved product masters appear. Inventory has {{ $inventoryFgCount }} active FG stock SKUs; each product needs an exact SKU mapping before planning.</span>
                    @error('finished_good_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Production site
                    <select class="access-input mt-1 w-full" name="site_id" id="plan-site" required>
                        <option value="">Select production site</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(old('site_id') == $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                        @endforeach
                    </select>
                    <span class="access-muted block text-xs mt-1">Scopes the plant and warehouses used to make and store this output.</span>
                    @error('site_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Plant (optional)
                    <input class="access-input mt-1 w-full" name="plant" value="{{ old('plant') }}" placeholder="Plant (e.g. Plant 1)">
                    <span class="access-muted block text-xs mt-1">A planning label when a site has more than one production area.</span>
                </label>
                <label class="block text-sm font-semibold">Target quantity
                    <input class="access-input mt-1 w-full" name="target_qty" type="number" step="0.001" min="0.001" value="{{ old('target_qty') }}" placeholder="Target quantity" required>
                    <span class="access-muted block text-xs mt-1">Target quantity is the demand to schedule; linked work orders cannot exceed it.</span>
                    @error('target_qty')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Unit of measure
                    <input class="access-input mt-1 w-full" name="uom" id="plan-uom" value="{{ old('uom') }}" placeholder="Select a finished good first" readonly required>
                    <span class="access-muted block text-xs mt-1">Filled from the approved finished-good master.</span>
                    @error('uom')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Due date
                    <input class="access-input mt-1 w-full" name="due_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('due_date', today()->toDateString()) }}" required>
                    <span class="access-muted block text-xs mt-1">The date production planning uses to prioritize this requirement.</span>
                    @error('due_date')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
            </div>
            <p id="plan-readiness" class="access-muted text-sm" role="status">Select a product and site to see whether their mapping is ready.</p>
            <button class="access-btn access-btn-primary">Create Plan</button>
        </form>
        @endif

        <section class="access-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div><h2 class="font-bold text-lg">Plan queue</h2><p class="access-muted text-sm mt-1">Open a plan for its sales source, assigned quantity, work orders and next action.</p></div>
                <span class="access-chip">{{ $plans->total() }} plans</span>
            </div>
            <div class="overflow-x-auto max-w-full">
                <table class="access-table">
                    <thead><tr><th>Plan / source</th><th>Finished good / site</th><th>Target</th><th>Assigned</th><th>Remaining</th><th>Status</th><th>Next action</th></tr></thead>
                    <tbody>
                    @forelse($plans as $plan)
                        @php($assignedQty = (float) $plan->workOrders->sum('target_qty'))
                        @php($remainingQty = max(0, round((float) $plan->target_qty - $assignedQty, 3)))
                        @php($planReady = $plan->site_id && $plan->finishedGood && strcasecmp(trim((string) $plan->uom), trim((string) $plan->finishedGood->uom)) === 0 && in_array($plan->site_id.':'.$plan->finished_good_id, $mappedPairs, true))
                        <tr>
                            <td><a class="font-semibold underline" href="{{ route('forge.plans.show', $plan) }}">{{ $plan->plan_number }}</a><br><span class="access-muted text-xs">{{ $plan->sourceOrderLine?->order?->order_number ? 'Sales order '.$plan->sourceOrderLine->order->order_number : 'Manual plan' }}</span></td>
                            <td>{{ $plan->finishedGood?->name ?? 'Product unavailable' }}<br><span class="access-muted text-xs">{{ $plan->site?->name ?? 'Site missing' }}</span></td>
                            <td>{{ number_format((float) $plan->target_qty, 3) }} {{ $plan->uom }}</td>
                            <td>{{ number_format($assignedQty, 3) }} {{ $plan->uom }}<br><span class="access-muted text-xs">{{ $plan->workOrders->count() }} work {{ $plan->workOrders->count() === 1 ? 'order' : 'orders' }}</span></td>
                            <td>{{ number_format($remainingQty, 3) }} {{ $plan->uom }}</td>
                            <td><span class="access-chip">{{ str($plan->status)->headline() }}</span></td>
                            <td>
                                @if($plan->status === 'draft' && $planReady && $canApprove)
                                    <form method="post" action="{{ route('forge.plans.approve', $plan) }}">@csrf<button class="access-btn" type="submit">Approve & Freeze</button></form>
                                @elseif($plan->status === 'frozen' && $remainingQty > 0 && $planReady && $canCreateWorkOrder)
                                    <a class="access-btn access-btn-primary" href="{{ route('forge.workorders.index', ['plan' => $plan->id]) }}#create-work-order">Create Work Order</a>
                                @elseif(!$planReady && $plan->workOrders->isEmpty())
                                    <a class="access-btn" href="{{ route('forge.plans.show', $plan) }}">Repair setup</a>
                                @elseif($remainingQty <= 0 && $plan->workOrders->isNotEmpty() && $plan->workOrders->every(fn ($wo) => $wo->status === 'closed'))
                                    <a class="access-btn" href="{{ route('forge.plans.show', $plan) }}">Review completed plan</a>
                                @elseif($plan->workOrders->isNotEmpty() && $canViewWorkOrders)
                                    <a class="access-btn" href="{{ route('forge.workorders.show', $plan->workOrders->last()) }}">Open Work Order</a>
                                @else
                                    <a class="access-btn" href="{{ route('forge.plans.show', $plan) }}">View Plan</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="access-muted text-center py-6">No plans yet. Create a manual plan above or start from a customer order.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $plans->links() }}</div>
        </section>
    </div>
    <script>
        const product = document.getElementById('plan-product');
        const site = document.getElementById('plan-site');
        const uom = document.getElementById('plan-uom');
        const readiness = document.getElementById('plan-readiness');
        const mappedPairs = @json($mappedPairs);
        function syncPlanningFields() {
            if (!product || !site) return;
            uom.value = product.selectedOptions[0]?.dataset.uom || '';
            if (!product.value || !site.value) { readiness.textContent = 'Select a product and site to see whether their mapping is ready.'; return; }
            readiness.textContent = mappedPairs.includes(site.value + ':' + product.value)
                ? 'Approved product-to-site mapping found. The ERP will also verify its active recipe and warehouses when you save.'
                : 'No approved FG mapping for this product at this site. Open Product & site mappings before creating the plan.';
        }
        product?.addEventListener('change', syncPlanningFields);
        site?.addEventListener('change', syncPlanningFields);
        syncPlanningFields();
    </script>
</x-app-layout>
