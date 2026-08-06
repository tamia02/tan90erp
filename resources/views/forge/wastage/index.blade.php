<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Wastage & Scrap</div>
            <h1 class="text-2xl font-bold">Wastage & Scrap</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.wastage.store') }}">
            @csrf
            <h2 class="font-bold">Record Wastage</h2>
            <div class="access-grid">
                <select class="access-input" name="work_order_id" required>
                    <option value="">Work order</option>
                    @foreach ($workOrders as $wo)
                        <option value="{{ $wo->id }}">{{ $wo->wo_number }}</option>
                    @endforeach
                </select>
                <input class="access-input" name="item_name" placeholder="Material/product" required>
                <input class="access-input" name="qty" type="number" step="0.001" min="0.001" placeholder="Quantity" required>
                <input class="access-input" name="uom" placeholder="UOM" required>
                <input class="access-input" name="reason" placeholder="Reason" required>
            </div>
            <button class="access-btn access-btn-primary">Record</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Records</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Work Order</th><th>Item</th><th>Qty</th><th>Reason</th><th>Disposition</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>{{ $record->workOrder?->wo_number }}</td>
                                <td>{{ $record->item_name }}</td>
                                <td>{{ $record->qty }} {{ $record->uom }}</td>
                                <td>{{ $record->reason }}</td>
                                <td><span class="access-chip">{{ str($record->disposition)->headline() }}</span></td>
                                <td>
                                    @if ($record->disposition === 'pending')
                                        <form method="post" action="{{ route('forge.wastage.approve', $record) }}" class="flex gap-2">
                                            @csrf
                                            <select class="access-input" name="disposition">
                                                <option value="rework">Rework</option>
                                                <option value="recover">Recover</option>
                                                <option value="return">Return</option>
                                                <option value="destruction">Destruction</option>
                                                <option value="approved_scrap">Approved Scrap</option>
                                            </select>
                                            <button class="access-btn">Disposition</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No wastage recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        </div>
    </div>
</x-app-layout>
