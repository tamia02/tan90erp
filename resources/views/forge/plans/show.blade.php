<x-app-layout>
    @include('access-control._style')
    @php($remainingQty = max(0, round((float) $plan->target_qty - $assignedQty, 3)))
    @php($fullyStored = $remainingQty <= 0 && $plan->workOrders->isNotEmpty() && $plan->workOrders->every(fn ($wo) => $wo->status === 'closed' && filled($wo->batch?->inventoryLot?->bin)))
    <div class="access-shell max-w-6xl mx-auto space-y-5">
        <header class="access-top p-5 sm:p-6">
            <div class="text-sm access-muted"><a class="underline" href="{{ route('forge.plans.index') }}">Production Plans</a> / {{ $plan->plan_number }}</div>
            <div class="flex flex-wrap items-start justify-between gap-3 mt-2">
                <div><h1 class="text-2xl sm:text-3xl font-bold">{{ $plan->plan_number }}</h1><p class="access-muted mt-1">{{ $plan->finishedGood?->code }} · {{ $plan->finishedGood?->name }}</p></div>
                <span class="access-chip">{{ str($plan->status)->headline() }}</span>
            </div>
        </header>

        @if(session('status'))<div class="access-card p-4 text-sm" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

        <section class="access-card p-5 sm:p-6">
            <h2 class="font-bold text-lg">Plan at a glance</h2>
            <div class="access-grid mt-4">
                <div><div class="access-muted text-xs uppercase">Target</div><div class="font-semibold">{{ number_format((float) $plan->target_qty, 3) }} {{ $plan->uom }}</div></div>
                <div><div class="access-muted text-xs uppercase">Assigned to work orders</div><div class="font-semibold">{{ number_format($assignedQty, 3) }} {{ $plan->uom }}</div></div>
                <div><div class="access-muted text-xs uppercase">Remaining</div><div class="font-semibold">{{ number_format($remainingQty, 3) }} {{ $plan->uom }}</div></div>
                <div><div class="access-muted text-xs uppercase">Production site</div><div class="font-semibold">{{ $plan->site?->name ?? 'Site not set' }}</div></div>
                <div><div class="access-muted text-xs uppercase">Due</div><div class="font-semibold">{{ $plan->due_date?->format('d M Y') ?? 'Not set' }}</div></div>
                <div><div class="access-muted text-xs uppercase">Source</div><div class="font-semibold">{{ $plan->sourceOrderLine?->order?->order_number ?? 'Manual plan' }}</div></div>
            </div>
            @if($plan->sourceOrderLine?->order && $canViewSalesOrders)
                <a class="access-btn mt-4" href="{{ route('flow.orders.show', $plan->sourceOrderLine->order) }}">Open linked sales order</a>
            @endif
        </section>

        <section class="access-card p-5 sm:p-6">
            <h2 class="font-bold text-lg">Next action</h2>
            @if($fullyStored)
                <p class="text-sm text-emerald-800 mt-1">Production complete. All planned work orders are closed, quality released, and their finished goods received and put away.</p>
            @elseif(!$planReady)
                <p class="text-sm text-amber-800 mt-1">This plan needs a valid site, product unit, approved recipe and product-to-warehouse mapping before another work order can start.</p>
                @if($canEdit)<a class="access-btn mt-4" href="#edit-plan">Repair this plan</a>@elseif($plan->workOrders->isNotEmpty())<p class="access-muted text-sm mt-2">Existing work orders remain available below for historical review.</p>@endif
            @elseif($plan->status === 'draft')
                <p class="access-muted text-sm mt-1">Approve the plan after checking the product master, recipe, exact FG SKU and site warehouses. An approved plan becomes frozen.</p>
                @if($canApprove)<form method="post" action="{{ route('forge.plans.approve', $plan) }}" class="mt-4">@csrf<button class="access-btn access-btn-primary" type="submit">Approve & Freeze Plan</button></form>@endif
            @elseif($plan->status === 'frozen' && $remainingQty > 0)
                <p class="access-muted text-sm mt-1">Create a work order for the remaining {{ number_format($remainingQty, 3) }} {{ $plan->uom }}. Its form opens with this plan and quantity selected.</p>
                @if($canCreateWorkOrder)<a class="access-btn access-btn-primary mt-4" href="{{ route('forge.workorders.index', ['plan' => $plan->id]) }}#create-work-order">Create Work Order</a>@endif
            @elseif($plan->workOrders->isNotEmpty())
                <p class="access-muted text-sm mt-1">The plan quantity is assigned. Open a work order below to continue material issue, mixing, production, QC or FG posting.</p>
            @else
                <p class="access-muted text-sm mt-1">This plan has no remaining quantity available for a new work order.</p>
            @endif
        </section>

        @if($canEdit)
        <form id="edit-plan" class="access-card p-5 sm:p-6 space-y-4" method="post" action="{{ route('forge.plans.update', $plan) }}">
            @csrf @method('PUT')
            <div><h2 class="font-bold text-lg">Edit manual plan</h2><p class="access-muted text-sm mt-1">A plan with no work orders can be corrected. Saving returns it to Draft so it can be approved again.</p></div>
            <div class="access-grid">
                <label class="block text-sm font-semibold">Finished good
                    <select class="access-input mt-1 w-full" id="edit-plan-product" name="finished_good_id" required>
                        @foreach($finishedGoods as $fg)<option value="{{ $fg->id }}" data-uom="{{ $fg->uom }}" @selected(old('finished_good_id', $plan->finished_good_id) == $fg->id)>{{ $fg->code }} — {{ $fg->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold">Production site
                    <select class="access-input mt-1 w-full" id="edit-plan-site" name="site_id" required>
                        <option value="">Select site</option>
                        @foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('site_id', $plan->site_id) == $location->id)>{{ $location->code }} — {{ $location->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold">Target quantity<input class="access-input mt-1 w-full" name="target_qty" type="number" min="0.001" step="0.001" value="{{ old('target_qty', $plan->target_qty) }}" required></label>
                <label class="block text-sm font-semibold">Unit of measure<input class="access-input mt-1 w-full" id="edit-plan-uom" name="uom" value="{{ old('uom', $plan->finishedGood?->uom) }}" readonly required><span class="access-muted block text-xs mt-1">Taken from the approved product master.</span></label>
                <label class="block text-sm font-semibold">Due date<input class="access-input mt-1 w-full" name="due_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('due_date', max(today()->toDateString(), $plan->due_date?->toDateString() ?? today()->toDateString())) }}" required></label>
                <label class="block text-sm font-semibold">Plant or production area (optional)<input class="access-input mt-1 w-full" name="plant" value="{{ old('plant', $plan->plant) }}" maxlength="100"></label>
            </div>
            <p id="edit-plan-readiness" class="access-muted text-sm" role="status"></p>
            <button class="access-btn access-btn-primary" type="submit">Save corrected plan</button>
        </form>
        @endif

        <section class="access-card p-5 sm:p-6">
            <h2 class="font-bold text-lg mb-3">Work orders from this plan</h2>
            <div class="overflow-x-auto max-w-full"><table class="access-table">
                <thead><tr><th>Work order</th><th>Target</th><th>Status</th><th>Next step</th></tr></thead>
                <tbody>
                @forelse($plan->workOrders as $wo)
                    <tr><td>{{ $wo->wo_number }}</td><td>{{ number_format((float) $wo->target_qty, 3) }} {{ $wo->uom }}</td><td><span class="access-chip">{{ str($wo->status)->headline() }}</span></td><td>@if($canViewWorkOrders)<a class="access-btn" href="{{ route('forge.workorders.show', $wo) }}">Open Work Order</a>@endif</td></tr>
                @empty
                    <tr><td colspan="4" class="access-muted text-center py-6">No work order yet. Freeze the plan, then create its first work order.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
    @if($canEdit)
    <script>
        const editProduct = document.getElementById('edit-plan-product');
        const editSite = document.getElementById('edit-plan-site');
        const editUom = document.getElementById('edit-plan-uom');
        const editReadiness = document.getElementById('edit-plan-readiness');
        const mappedPairs = @json($mappedPairs);
        function syncEditedPlan() {
            editUom.value = editProduct.selectedOptions[0]?.dataset.uom || '';
            editReadiness.textContent = mappedPairs.includes(editSite.value + ':' + editProduct.value)
                ? 'Approved product and site mapping found. Save to check the recipe and warehouses.'
                : 'This product and site need an approved FG mapping before the plan can be saved.';
        }
        editProduct.addEventListener('change', syncEditedPlan);
        editSite.addEventListener('change', syncEditedPlan);
        syncEditedPlan();
    </script>
    @endif
</x-app-layout>
