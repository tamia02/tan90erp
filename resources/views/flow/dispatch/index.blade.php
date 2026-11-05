<x-app-layout>
    @include('access-control._style')
    <style>
        #flow-dispatch .dispatch-field{display:grid;gap:.35rem;align-content:start;color:#53635b;font-size:.78rem;font-weight:700}
        #flow-dispatch .dispatch-field .access-input{color:#17211b;font-weight:400}
        #flow-dispatch .access-btn{min-height:2.75rem}
        #flow-dispatch :is(input,select,textarea,button,a):focus-visible{outline:3px solid #65a98a;outline-offset:2px}
        #flow-dispatch .dispatch-card{border:1px solid #dfe7e2;border-radius:9px;padding:1rem;background:#fff}
    </style>
    <div id="flow-dispatch" class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Dispatch</div>
            <h1 class="text-2xl font-bold">Dispatch</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm" role="status" aria-live="polite">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.dispatch.store') }}">
            @csrf
            <h2 class="font-bold">Create Shipment</h2>
            <div class="access-grid">
                <label class="dispatch-field">Dispatch warehouse<input class="access-input" name="warehouse" placeholder="Select or enter warehouse" value="Bhiwandi FG Warehouse"></label>
                <label class="dispatch-field">Dock number<input class="access-input" name="dock_number" placeholder="Enter dock number"></label>
                <label class="dispatch-field">Transporter<input class="access-input" name="transporter" placeholder="Enter transporter"></label>
                <label class="dispatch-field">Vehicle number<input class="access-input" name="vehicle_number" placeholder="Enter vehicle number"></label>
                <label class="dispatch-field">Driver name<input class="access-input" name="driver_name" placeholder="Enter driver name"></label>
                <label class="dispatch-field">Temperature logger ID<input class="access-input" name="temperature_logger_id" placeholder="Enter logger ID"></label>
                <label class="dispatch-field">Shipment seal number<input class="access-input" name="seal_number" placeholder="Enter seal number"></label>
            </div>
            <button class="access-btn access-btn-primary">Create Shipment</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Sealed Handling Units Awaiting Load</h2>
            <form method="get" class="access-grid mb-3">
                <label class="dispatch-field">Search unit/order<input class="access-input" name="unit_q" value="{{ request('unit_q') }}" maxlength="100"></label>
                <label class="dispatch-field">Search shipment<input class="access-input" name="shipment_q" value="{{ request('shipment_q') }}" maxlength="100"></label>
                <button class="access-btn">Find units and shipments</button>
            </form>
            @if($sealedUnits->isNotEmpty() && $loadableShipments->isNotEmpty())
                <form method="post" action="{{ route('flow.dispatch.assign-unit') }}" class="access-grid">
                    @csrf
                    <label class="dispatch-field">Sealed handling unit<select class="access-input" name="handling_unit_id" required>
                        @foreach($sealedUnits as $hu)<option value="{{ $hu->id }}">{{ $hu->hu_number }} — {{ $hu->order?->order_number }} — {{ $hu->order?->customer_name }}</option>@endforeach
                    </select></label>
                    <label class="dispatch-field">Shipment<select class="access-input" name="shipment_id" required>
                        @foreach($loadableShipments as $available)<option value="{{ $available->id }}">{{ $available->shipment_number }}</option>@endforeach
                    </select></label>
                    <button class="access-btn access-btn-primary">Load onto this shipment</button>
                </form>
            @else
                <p class="access-muted text-sm">No matching sealed units or loadable shipments.</p>
            @endif
            {{ $sealedUnits->links() }}
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Shipments</h2>
            <div class="space-y-4">
                @forelse ($shipments as $shipment)
                    <div class="dispatch-card">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <strong>{{ $shipment->shipment_number }}</strong>
                            <span class="access-chip">{{ str($shipment->status)->headline() }}</span>
                        </div>
                        <div class="access-muted text-sm">{{ $shipment->dock_number }} · {{ $shipment->transporter }} · {{ $shipment->vehicle_number }}</div>

                        @if (in_array($shipment->status, ['planned', 'loading']))
                            <form method="post" action="{{ route('flow.dispatch.release', $shipment) }}" class="mt-2">
                                @csrf
                                <button class="access-btn access-btn-primary" aria-label="Release shipment {{ $shipment->shipment_number }}">Release Shipment</button>
                            </form>
                        @endif

                        <form method="post" action="{{ route('flow.dispatch.temperature', $shipment) }}" class="mt-2 flex gap-2 items-end flex-wrap">
                            @csrf
                            <label class="dispatch-field">Temperature (°C)<input class="access-input" style="width:140px" name="reading_celsius" type="number" step="0.01" placeholder="e.g. -18.00" required></label>
                            <label class="text-sm flex items-center gap-2 min-h-11"><input type="checkbox" name="excursion" value="1"> Temperature excursion</label>
                            <label class="dispatch-field flex-1 min-w-48">Reading notes <input class="access-input" name="notes" placeholder="Optional context or observation"></label>
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
                                            <label class="dispatch-field">Excursion disposition<select class="access-input" name="disposition" style="width:220px">
                                                <option value="release">Release</option>
                                                <option value="customer_deviation">Customer Deviation</option>
                                                <option value="return_to_warehouse">Return to Warehouse</option>
                                            </select></label>
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
