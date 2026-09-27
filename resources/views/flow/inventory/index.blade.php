<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / FG Inventory</div>
            <h1 class="text-2xl font-bold">Finished-Goods Inventory & Ledger</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Released Batches Awaiting Receipt (from Forge)</h2>
            <div class="space-y-2">
                @forelse ($releasableBatches as $batch)
                    <form class="border rounded p-3 flex items-center justify-between gap-3 flex-wrap" style="border-color:#dfe7e2" method="post" action="{{ route('flow.inventory.receive', $batch) }}">
                        @csrf
                        <div class="text-sm">
                            <strong>{{ $batch->batch_number }}</strong> — {{ $batch->workOrder?->finishedGood?->name }}
                            <span class="access-muted">· {{ $batch->qty }} {{ $batch->uom }}</span>
                        </div>
                        <div class="flex gap-2">
                            <input class="access-input" name="zone" placeholder="Zone (optional)">
                            <input class="access-input" name="bin" placeholder="Bin (optional)">
                            <button class="access-btn access-btn-primary">Receive</button>
                        </div>
                    </form>
                @empty
                    <p class="access-muted text-sm">No released Forge batches awaiting receipt.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Inventory Lots</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Lot</th><th>Finished Good</th><th>Available/Allocated/Picked</th><th>Zone/Bin</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($lots as $lot)
                            <tr>
                                <td>{{ $lot->lot_number }}</td>
                                <td>{{ $lot->finishedGood?->name }}</td>
                                <td>{{ $lot->qty_available }} / {{ $lot->qty_allocated }} / {{ $lot->qty_picked }} {{ $lot->uom }}</td>
                                <td>{{ $lot->zone ?? '—' }} / {{ $lot->bin ?? '—' }}</td>
                                <td><span class="access-chip">{{ str($lot->status)->headline() }}</span></td>
                                <td>
                                    @if ($lot->status === 'staged')
                                        <form method="post" action="{{ route('flow.inventory.putaway', $lot) }}" class="flex gap-2">
                                            @csrf
                                            <input class="access-input" name="zone" placeholder="Zone" required>
                                            <input class="access-input" name="bin" placeholder="Bin" required>
                                            <button class="access-btn">Put Away</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No FG inventory received yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $lots->links() }}
        </div>
    </div>
</x-app-layout>
