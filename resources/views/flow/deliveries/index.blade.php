<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Delivery & POD</div>
            <h1 class="text-2xl font-bold">Delivery & POD Closure</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Shipments In Transit</h2>
            <div class="space-y-3">
                @forelse ($inTransitShipments as $shipment)
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <strong>{{ $shipment->shipment_number }}</strong>
                        @foreach ($shipment->handlingUnits->pluck('order')->unique('id') as $order)
                            <form method="post" action="{{ route('flow.deliveries.store', $shipment) }}" class="mt-2 space-y-2">
                                @csrf
                                <input type="hidden" name="customer_order_id" value="{{ $order->id }}">
                                <div class="text-sm font-medium">{{ $order->order_number }} — {{ $order->customer_name }}</div>
                                <div class="access-grid">
                                    <input class="access-input" name="receiver_name" placeholder="Receiver name">
                                    <input class="access-input" name="qty_accepted" type="number" step="0.001" min="0" placeholder="Qty accepted">
                                    <input class="access-input" name="pod_reference" placeholder="POD reference" required>
                                </div>
                                <textarea class="access-input" name="exception_notes" placeholder="Exception notes (if any)"></textarea>
                                <button class="access-btn access-btn-primary">Record POD</button>
                            </form>
                        @endforeach
                    </div>
                @empty
                    <p class="access-muted text-sm">No shipments in transit.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Deliveries</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Order</th><th>Shipment</th><th>Receiver</th><th>POD Ref</th><th>Delivered</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($deliveries as $delivery)
                            <tr>
                                <td>{{ $delivery->order?->order_number }}</td>
                                <td>{{ $delivery->shipment?->shipment_number }}</td>
                                <td>{{ $delivery->receiver_name ?? '—' }}</td>
                                <td>{{ $delivery->pod_reference }}</td>
                                <td>{{ $delivery->delivered_at?->format('d M Y, H:i') }}</td>
                                <td>
                                    @if (! $delivery->closed_at)
                                        <form method="post" action="{{ route('flow.deliveries.close', $delivery) }}">
                                            @csrf
                                            <button class="access-btn access-btn-primary">Close Delivery</button>
                                        </form>
                                    @else
                                        <span class="access-muted text-sm">Closed</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No deliveries recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $deliveries->links() }}
        </div>
    </div>
</x-app-layout>
