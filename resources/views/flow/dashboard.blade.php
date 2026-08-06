<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow</div>
            <h1 class="text-2xl font-bold">Fulfilment Command Centre</h1>
        </div>

        <div class="access-grid">
            <div class="access-card p-4">
                <div class="access-muted text-sm">Orders (all statuses)</div>
                <div class="workspace-metric">{{ $statusCounts->sum() }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Shipments In Transit</div>
                <div class="workspace-metric">{{ $inTransitShipments }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Open Returns / Claims</div>
                <div class="workspace-metric">{{ $openReturns }}</div>
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Orders by Status</h2>
            <div class="flex flex-wrap gap-2">
                @forelse ($statusCounts as $status => $count)
                    <span class="access-chip">{{ str($status)->replace('_', ' ')->headline() }}: {{ $count }}</span>
                @empty
                    <p class="access-muted text-sm">No orders yet.</p>
                @endforelse
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Awaiting Allocation</h2>
                <div class="space-y-2">
                    @forelse ($awaitingAllocation as $order)
                        <a href="{{ route('flow.orders.show', $order) }}" class="block border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            <strong>{{ $order->order_number }}</strong> — {{ $order->customer_name }}
                            <span class="access-chip ml-2">{{ str($order->status)->headline() }}</span>
                        </a>
                    @empty
                        <p class="access-muted text-sm">Nothing waiting.</p>
                    @endforelse
                </div>
            </section>
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">In Transit</h2>
                <div class="space-y-2">
                    @forelse ($inTransit as $order)
                        <a href="{{ route('flow.orders.show', $order) }}" class="block border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            <strong>{{ $order->order_number }}</strong> — {{ $order->customer_name }}
                        </a>
                    @empty
                        <p class="access-muted text-sm">Nothing in transit.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Jump to</h2>
            <div class="flex flex-wrap gap-2">
                <a class="access-btn" href="{{ route('flow.inventory.index') }}">FG Inventory & Ledger</a>
                <a class="access-btn" href="{{ route('flow.orders.index') }}">Customer Orders</a>
                <a class="access-btn" href="{{ route('flow.waves.index') }}">Wave Builder & Picking</a>
                <a class="access-btn" href="{{ route('flow.packing.index') }}">Packing</a>
                <a class="access-btn" href="{{ route('flow.dispatch.index') }}">Dispatch</a>
                <a class="access-btn" href="{{ route('flow.deliveries.index') }}">Delivery & POD</a>
                <a class="access-btn" href="{{ route('flow.returns.index') }}">Returns, RMA & Claims</a>
            </div>
        </div>
    </div>
</x-app-layout>
