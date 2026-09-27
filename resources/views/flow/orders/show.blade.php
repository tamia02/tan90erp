<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between flex-wrap gap-3">
            <div>
                <div class="text-sm access-muted">Flow / Orders / {{ $order->order_number }}</div>
                <h1 class="text-2xl font-bold">{{ $order->order_number }} — {{ $order->customer_name }}</h1>
            </div>
            <span class="access-chip">{{ str($order->status)->replace('_', ' ')->headline() }}</span>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-card p-5 flex flex-wrap gap-2">
            <h2 class="font-bold w-full mb-1">Actions</h2>
            @if ($order->status === 'draft')
                <form method="post" action="{{ route('flow.orders.validate', $order) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Validate</button></form>
            @endif
            @if ($order->status === 'validated')
                <form method="post" action="{{ route('flow.orders.release', $order) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Release (runs ATP + FEFO allocation)</button></form>
            @endif
        </div>

        @if ($order->status === 'draft')
            <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.orders.lines.store', $order) }}">
                @csrf
                <h2 class="font-bold">Add Line</h2>
                <div class="access-grid">
                    <select class="access-input" name="finished_good_id" required>
                        <option value="">Finished good</option>
                        @foreach ($finishedGoods as $fg)
                            <option value="{{ $fg->id }}">{{ $fg->code }} — {{ $fg->name }}</option>
                        @endforeach
                    </select>
                    <input class="access-input" name="qty_ordered" type="number" step="0.001" min="0.001" placeholder="Quantity" required>
                    <input class="access-input" name="uom" placeholder="UOM" required>
                </div>
                <button class="access-btn access-btn-primary">Add Line</button>
            </form>
        @endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Lines</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Finished Good</th><th>Ordered</th><th>Allocated</th><th>Picked</th><th>Packed</th></tr></thead>
                    <tbody>
                        @forelse ($order->lines as $line)
                            <tr>
                                <td>{{ $line->finishedGood?->name }}</td>
                                <td>{{ $line->qty_ordered }} {{ $line->uom }}</td>
                                <td>{{ $line->qty_allocated }}</td>
                                <td>{{ $line->qty_picked }}</td>
                                <td>{{ $line->qty_packed }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No lines yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Allocations (FEFO)</h2>
            <div class="space-y-2">
                @forelse ($order->lines as $line)
                    @foreach ($line->allocations as $allocation)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $line->finishedGood?->name }} — lot {{ $allocation->inventoryLot?->lot_number }} — {{ $allocation->qty }} {{ $line->uom }}
                            <span class="access-chip ml-2">{{ $allocation->status }}</span>
                        </div>
                    @endforeach
                @empty
                    <p class="access-muted text-sm">No allocations yet.</p>
                @endforelse
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Handling Units</h2>
                <div class="space-y-2">
                    @forelse ($order->handlingUnits as $hu)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $hu->hu_number }} — <span class="access-chip">{{ $hu->status }}</span>
                            <span class="access-muted">· shipment {{ $hu->shipment?->shipment_number ?? 'unassigned' }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">Nothing packed yet.</p>
                    @endforelse
                </div>
            </section>
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Returns</h2>
                <div class="space-y-2">
                    @forelse ($order->returns as $return)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $return->rma_number }} — {{ $return->reason }}
                            <span class="access-chip ml-2">{{ $return->status }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No returns.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
