<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Returns, RMA & Claims</div>
            <h1 class="text-2xl font-bold">Returns, RMA & Claims</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.returns.store') }}">
            @csrf
            <h2 class="font-bold">Request RMA</h2>
            <div class="access-grid">
                <select class="access-input" name="customer_order_id" required>
                    <option value="">Order</option>
                    @foreach ($closedOrders as $order)
                        <option value="{{ $order->id }}">{{ $order->order_number }} — {{ $order->customer_name }}</option>
                    @endforeach
                </select>
                <input class="access-input" name="reason" placeholder="Reason" required>
                <input class="access-input" name="qty" type="number" step="0.001" min="0.001" placeholder="Quantity" required>
                <input class="access-input" name="uom" placeholder="UOM" required>
            </div>
            <button class="access-btn access-btn-primary">Request RMA</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Returns</h2>
            <div class="space-y-3">
                @forelse ($returns as $return)
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <strong>{{ $return->rma_number }}</strong> — {{ $return->order?->order_number }}
                            <span class="access-chip">{{ str($return->status)->headline() }}</span>
                        </div>
                        <div class="access-muted text-sm">{{ $return->reason }} — {{ $return->qty }} {{ $return->uom }}</div>

                        @if ($return->status === 'requested' || $return->status === 'received')
                            <form method="post" action="{{ route('flow.returns.inspect', $return) }}" class="mt-2 space-y-2">
                                @csrf
                                <select class="access-input" name="disposition" required>
                                    <option value="restock">Restock after QC</option>
                                    <option value="rework">Rework through Forge</option>
                                    <option value="scrap">Scrap</option>
                                    <option value="reject">Reject RMA</option>
                                </select>
                                <textarea class="access-input" name="inspection_notes" placeholder="Inspection notes"></textarea>
                                <label class="text-sm flex items-center gap-2"><input type="checkbox" name="claim_raised" value="1"> Raise customer/transporter claim</label>
                                <input class="access-input" name="claim_amount" type="number" step="0.01" min="0" placeholder="Claim amount (if raised)">
                                <button class="access-btn access-btn-primary">Disposition</button>
                            </form>
                        @else
                            <div class="text-sm mt-1">Disposition: {{ str($return->disposition ?? '—')->headline() }}
                                @if ($return->claim_raised)
                                    · Claim: {{ $return->claim_status }} (₹{{ $return->claim_amount }})
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="access-muted text-sm">No returns requested yet.</p>
                @endforelse
            </div>
            {{ $returns->links() }}
        </div>
    </div>
</x-app-layout>
