<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Packing</div>
            <h1 class="text-2xl font-bold">Packing, Cartonisation & Labelling</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Pack an Order</h2>
            <div class="space-y-3">
                @forelse ($pickedOrders as $order)
                    <form class="border rounded p-3 flex items-center gap-3 flex-wrap" style="border-color:#dfe7e2" method="post" action="{{ route('flow.packing.store', $order) }}">
                        @csrf
                        <div class="text-sm"><strong>{{ $order->order_number }}</strong> — {{ $order->customer_name }} <span class="access-chip">{{ $order->status }}</span></div>
                        <input class="access-input" style="width:140px" name="qty_packed" type="number" step="0.001" min="0.001" placeholder="Qty packed" required>
                        <input class="access-input" style="width:140px" name="weight_kg" type="number" step="0.001" min="0" placeholder="Weight (kg)">
                        <button class="access-btn access-btn-primary">Pack New Carton</button>
                    </form>
                @empty
                    <p class="access-muted text-sm">No orders currently being picked/packed.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Handling Units</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>HU</th><th>Order</th><th>Weight</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($handlingUnits as $hu)
                            <tr>
                                <td>{{ $hu->hu_number }}</td>
                                <td>{{ $hu->order?->order_number }}</td>
                                <td>{{ $hu->weight_kg ?? '—' }} kg</td>
                                <td><span class="access-chip">{{ str($hu->status)->headline() }}</span></td>
                                <td>
                                    @if ($hu->status === 'packed')
                                        <form method="post" action="{{ route('flow.packing.seal', $hu) }}">
                                            @csrf
                                            <button class="access-btn access-btn-primary">Seal</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No handling units packed yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $handlingUnits->links() }}
        </div>
    </div>
</x-app-layout>
