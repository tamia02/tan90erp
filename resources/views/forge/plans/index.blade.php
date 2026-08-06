<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Production Plans</div>
            <h1 class="text-2xl font-bold">Demand, MRP & Production Plans</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.plans.store') }}">
            @csrf
            <h2 class="font-bold">Create Production Plan</h2>
            <div class="access-grid">
                <select class="access-input" name="finished_good_id" required>
                    <option value="">Finished good</option>
                    @foreach ($finishedGoods as $fg)
                        <option value="{{ $fg->id }}">{{ $fg->code }} — {{ $fg->name }}</option>
                    @endforeach
                </select>
                <input class="access-input" name="plant" placeholder="Plant (e.g. Plant 1)">
                <input class="access-input" name="target_qty" type="number" step="0.001" min="0.001" placeholder="Target quantity" required>
                <input class="access-input" name="uom" placeholder="UOM (e.g. EA)" required>
                <input class="access-input" name="due_date" type="date" required>
            </div>
            <button class="access-btn access-btn-primary">Create Plan</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Plans</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Plan</th><th>Finished Good</th><th>Target Qty</th><th>Due</th><th>Status</th><th>Approved By</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($plans as $plan)
                            <tr>
                                <td>{{ $plan->plan_number }}</td>
                                <td>{{ $plan->finishedGood?->name }}</td>
                                <td>{{ $plan->target_qty }} {{ $plan->uom }}</td>
                                <td>{{ $plan->due_date->format('d M Y') }}</td>
                                <td><span class="access-chip">{{ str($plan->status)->headline() }}</span></td>
                                <td>{{ $plan->approver?->name ?? '—' }}</td>
                                <td>
                                    @if ($plan->status === 'draft')
                                        <form method="post" action="{{ route('forge.plans.approve', $plan) }}">
                                            @csrf
                                            <button class="access-btn">Approve & Freeze</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="access-muted text-center py-6">No production plans yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $plans->links() }}
        </div>
    </div>
</x-app-layout>
