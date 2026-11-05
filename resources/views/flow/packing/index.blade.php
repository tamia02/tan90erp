<x-app-layout>
    @include('access-control._style')
    <style>
        #flow-packing :is(input,select,button,a):focus-visible{outline:3px solid #65a98a;outline-offset:2px}
        #flow-packing .access-btn{min-height:2.75rem}
        #flow-packing .flow-hu-table{overflow:auto;max-width:100%;-webkit-overflow-scrolling:touch}
        #flow-packing .flow-hu-table .access-table{min-width:620px}
        @media(max-width:720px){
            #flow-packing .flow-hu-table{overflow:visible}
            #flow-packing .flow-hu-table .access-table{min-width:0;border-spacing:0 .75rem}
            #flow-packing .flow-hu-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
            #flow-packing .flow-hu-table tbody{display:grid;gap:.65rem}
            #flow-packing .flow-hu-table tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.35rem .75rem;padding:.75rem;background:#fff;border:1px solid #dfe7e2;border-radius:8px}
            #flow-packing .flow-hu-table td{display:grid;gap:.2rem;min-width:0;padding:.35rem 0;border:0;overflow-wrap:anywhere;font-size:.85rem}
            #flow-packing .flow-hu-table td:before{content:attr(data-label);color:#66736c;font-size:.65rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
            #flow-packing .flow-hu-table td:first-child,#flow-packing .flow-hu-table td:last-child{grid-column:1/-1}
            #flow-packing .flow-hu-table td:first-child{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700}
        }
    </style>
    <div id="flow-packing" class="access-shell max-w-7xl mx-auto space-y-5">
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
                        @if($order->site_id)
                            @foreach($order->lines as $index => $line)
                                <label class="text-sm">{{ $line->finishedGood?->name }} · picked {{ $line->qty_picked }} / packed {{ $line->qty_packed }}
                                    <input type="hidden" name="lines[{{ $index }}][order_line_id]" value="{{ $line->id }}">
                                    <input class="access-input" style="width:140px" name="lines[{{ $index }}][qty]" type="number" step="0.001" min="0" max="{{ max(0, (float)$line->qty_picked - (float)$line->qty_packed) }}" placeholder="Qty in carton">
                                </label>
                            @endforeach
                        @else
                            <input class="access-input" style="width:140px" name="qty_packed" type="number" step="0.001" min="0.001" placeholder="Qty packed" required>
                        @endif
                        <label class="text-xs access-muted">Carton weight (kg)<input class="access-input" style="width:140px" name="weight_kg" type="number" step="0.001" min="0" placeholder="0.000"></label>
                        <button class="access-btn access-btn-primary">Pack New Carton</button>
                    </form>
                @empty
                    <p class="access-muted text-sm">No orders currently being picked/packed.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Handling Units</h2>
            <div class="flow-hu-table" role="region" aria-label="Packed handling units">
                <table class="access-table">
                    <thead><tr><th>HU</th><th>Order</th><th>Weight</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($handlingUnits as $hu)
                            <tr>
                                <td data-label="Handling unit">{{ $hu->hu_number }}</td>
                                <td data-label="Order">{{ $hu->order?->order_number }}</td>
                                <td data-label="Carton weight">{{ $hu->weight_kg ?? '—' }} kg</td>
                                <td data-label="Status"><span class="access-chip">{{ str($hu->status)->headline() }}</span></td>
                                <td data-label="Next action">
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
