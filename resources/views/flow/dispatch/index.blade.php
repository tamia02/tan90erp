<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Dispatch</div>
            <h1 class="text-2xl font-bold">Dispatch</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.dispatch.store') }}">
            @csrf
            <h2 class="font-bold">Create Shipment</h2>
            <div class="access-grid">
                <input class="access-input" name="warehouse" placeholder="Warehouse" value="Bhiwandi FG Warehouse">
                <input class="access-input" name="dock_number" placeholder="Dock number">
                <input class="access-input" name="transporter" placeholder="Transporter">
                <input class="access-input" name="vehicle_number" placeholder="Vehicle number">
                <input class="access-input" name="driver_name" placeholder="Driver name">
                <input class="access-input" name="temperature_logger_id" placeholder="Temperature logger ID">
                <input class="access-input" name="seal_number" placeholder="Seal number">
            </div>
            <button class="access-btn access-btn-primary">Create Shipment</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Sealed Handling Units Awaiting Load</h2>
            <div class="space-y-2">
                @forelse ($sealedUnits as $hu)
                    <div class="text-sm border rounded p-2" style="border-color:#dfe7e2">{{ $hu->hu_number }} — {{ $hu->order?->order_number }} — {{ $hu->order?->customer_name }}</div>
                @empty
                    <p class="access-muted text-sm">Nothing sealed and unassigned right now.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Shipments</h2>
            <div class="space-y-4">
                @forelse ($shipments as $shipment)
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <strong>{{ $shipment->shipment_number }}</strong>
                            <span class="access-chip">{{ str($shipment->status)->headline() }}</span>
                        </div>
                        <div class="access-muted text-sm">{{ $shipment->dock_number }} · {{ $shipment->transporter }} · {{ $shipment->vehicle_number }}</div>

                        @if ($shipment->status === 'planned' || $shipment->status === 'loading')
                            <div class="mt-2 space-y-1">
                                @forelse ($sealedUnits as $hu)
                                    <form method="post" action="{{ route('flow.dispatch.load', [$shipment, $hu]) }}" class="flex items-center gap-2 text-sm">
                                        @csrf
                                        <span>{{ $hu->hu_number }} — {{ $hu->order?->order_number }}</span>
                                        <button class="access-btn">Load onto this shipment</button>
                                    </form>
                                @empty
                                @endforelse
                            </div>
                        @endif

                        @if (in_array($shipment->status, ['planned', 'loading']))
                            <form method="post" action="{{ route('flow.dispatch.release', $shipment) }}" class="mt-2">
                                @csrf
                                <button class="access-btn access-btn-primary">Release Shipment</button>
                            </form>
                        @endif

                        <form method="post" action="{{ route('flow.dispatch.temperature', $shipment) }}" class="mt-2 flex gap-2 items-center">
                            @csrf
                            <input class="access-input" style="width:120px" name="reading_celsius" type="number" step="0.01" placeholder="°C" required>
                            <label class="text-sm flex items-center gap-1"><input type="checkbox" name="excursion" value="1"> Excursion</label>
                            <button class="access-btn">Log Reading</button>
                        </form>

                        @foreach ($shipment->temperatureEvents as $event)
                            <div class="text-sm mt-1">
                                {{ $event->reading_celsius }}°C
                                @if ($event->excursion)
                                    <span class="access-chip">Excursion</span>
                                    @if (! $event->disposition)
                                        <form method="post" action="{{ route('flow.dispatch.temperature.disposition', $event) }}" class="inline-flex gap-1">
                                            @csrf
                                            <select class="access-input" name="disposition" style="width:180px">
                                                <option value="release">Release</option>
                                                <option value="customer_deviation">Customer Deviation</option>
                                                <option value="return_to_warehouse">Return to Warehouse</option>
                                            </select>
                                            <button class="access-btn">Disposition</button>
                                        </form>
                                    @else
                                        <span class="access-muted">{{ $event->disposition }}</span>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="access-muted text-sm">No shipments yet.</p>
                @endforelse
            </div>
            {{ $shipments->links() }}
        </div>
    </div>
</x-app-layout>
