<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Work Orders</div>
            <h1 class="text-2xl font-bold">Work Orders</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.workorders.store') }}">
            @csrf
            <h2 class="font-bold">Create Work Order from an Approved Plan</h2>
            <div class="access-grid">
                <select class="access-input" name="production_plan_id" id="plan-select" required>
                    <option value="">Approved production plan</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" data-finished-good="{{ $plan->finished_good_id }}" data-uom="{{ $plan->uom }}">
                            {{ $plan->plan_number }} — {{ $plan->finishedGood?->name }}
                        </option>
                    @endforeach
                </select>
                <input class="access-input" name="batch_number" placeholder="Batch number (optional)">
                <input class="access-input" name="target_qty" type="number" step="0.001" min="0.001" placeholder="Target quantity" required>
                <input class="access-input" name="uom" placeholder="UOM" required>
                <input class="access-input" name="plant" placeholder="Plant">
            </div>
            <p class="access-muted text-sm">BOM/Recipe/Routing snapshot: pick the plan's finished good below to auto-load its released definitions (leave blank to create a bare work order for now).</p>
            <input type="hidden" name="finished_good_id" id="finished-good-input">
            <button class="access-btn access-btn-primary">Create Work Order</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Work Orders</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>WO</th><th>Finished Good</th><th>Target Qty</th><th>Good/Rework/Rejected</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($workOrders as $wo)
                            <tr>
                                <td>{{ $wo->wo_number }}</td>
                                <td>{{ $wo->finishedGood?->name }}</td>
                                <td>{{ $wo->target_qty }} {{ $wo->uom }}</td>
                                <td>{{ $wo->good_qty }} / {{ $wo->rework_qty }} / {{ $wo->rejected_qty }}</td>
                                <td><span class="access-chip">{{ str($wo->status)->replace('_', ' ')->headline() }}</span></td>
                                <td><a class="access-btn" href="{{ route('forge.workorders.show', $wo) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No work orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $workOrders->links() }}
        </div>
    </div>

    <script>
        document.getElementById('plan-select')?.addEventListener('change', function (e) {
            var opt = e.target.selectedOptions[0];
            document.getElementById('finished-good-input').value = opt?.dataset.finishedGood || '';
        });
    </script>
</x-app-layout>
