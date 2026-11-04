<x-app-layout>
    @include('access-control._style')
    <style>
        #flow-fg-inventory :is(input,select,button,a):focus-visible{outline:3px solid #65a98a;outline-offset:2px}
        #flow-fg-inventory .access-btn{min-height:2.75rem}
        #flow-fg-inventory .flow-inventory-lots{overflow:auto;max-width:100%;-webkit-overflow-scrolling:touch}
        #flow-fg-inventory .flow-inventory-lots .access-table{min-width:920px}
        @media(max-width:720px){
            #flow-fg-inventory .flow-inventory-lots{overflow:visible}
            #flow-fg-inventory .flow-inventory-lots .access-table{min-width:0;border-spacing:0 .75rem}
            #flow-fg-inventory .flow-inventory-lots thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
            #flow-fg-inventory .flow-inventory-lots tbody{display:grid;gap:.65rem}
            #flow-fg-inventory .flow-inventory-lots tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.4rem .75rem;padding:.75rem;background:#fff;border:1px solid #dfe7e2;border-radius:8px;box-shadow:0 1px 2px #17211b0a}
            #flow-fg-inventory .flow-inventory-lots td{display:grid;gap:.2rem;min-width:0;padding:.35rem 0;border:0;overflow-wrap:anywhere;font-size:.85rem}
            #flow-fg-inventory .flow-inventory-lots td:before{content:attr(data-label);color:#66736c;font-size:.65rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
            #flow-fg-inventory .flow-inventory-lots td:nth-child(1),#flow-fg-inventory .flow-inventory-lots td:nth-child(2),#flow-fg-inventory .flow-inventory-lots td:nth-child(7){grid-column:1/-1}
            #flow-fg-inventory .flow-inventory-lots td:nth-child(1){font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700}
            #flow-fg-inventory .flow-inventory-lots td:nth-child(7) form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem}
        }
    </style>
    <div id="flow-fg-inventory" class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Production / Step 06 of 06</div>
            <h1 class="text-2xl font-bold">FG Store · Receive & Bin Putaway</h1>
            <p class="access-muted text-sm mt-2">Receive the QC-released batch once, choose an approved bin in its mapped FG warehouse, then record putaway time and people.</p>
            @if($focusedWorkOrderId)<a class="access-btn mt-3" href="{{ route('flow.inventory.index') }}">View all FG lots</a>@endif
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
                        <div class="flex gap-2 flex-wrap items-start">
                            @if ((int) ($batch->workOrder?->workflow_version ?? 1) >= 2)
                                <span class="access-muted text-sm">Receive staged FG; choose a master bin in the next step.</span>
                            @else
                            <div>
                                <input class="access-input" name="zone" placeholder="Zone" value="{{ $batch->suggested_zone }}" aria-label="FG storage zone" required>
                                @if ($batch->suggested_zone || $batch->suggested_bin)
                                    <div class="access-muted text-xs mt-1">Suggested from same FG / site / warehouse</div>
                                @endif
                            </div>
                            <input class="access-input" name="bin" placeholder="Bin" value="{{ $batch->suggested_bin }}" aria-label="FG storage bin" required>
                            <label class="text-xs access-muted">Putaway start<input class="access-input" name="putaway_started_at" type="datetime-local" required></label>
                            <label class="text-xs access-muted">Putaway end<input class="access-input" name="putaway_ended_at" type="datetime-local" required></label>
                            <input class="access-input" name="putaway_manpower" type="number" min="1" step="1" placeholder="People" aria-label="Putaway manpower" required>
                            @endif
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
            <div class="flow-inventory-lots" role="region" aria-label="Finished goods lots">
                <table class="access-table">
                    <thead><tr><th>Lot</th><th>Finished Good</th><th>Available/Allocated/Picked</th><th>Zone/Bin</th><th>Putaway time / people</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($lots as $lot)
                            <tr>
                                <td data-label="Lot">{{ $lot->lot_number }}</td>
                                <td data-label="Finished good">{{ $lot->finishedGood?->name }}</td>
                                <td data-label="Available / allocated / picked">{{ $lot->qty_available }} / {{ $lot->qty_allocated }} / {{ $lot->qty_picked }} {{ $lot->uom }}</td>
                                <td data-label="Zone / bin">{{ $lot->zone ?? '—' }} / {{ $lot->bin ?? '—' }}</td>
                                <td data-label="Putaway time / people">
                                    @if ($lot->putaway_started_at && $lot->putaway_ended_at)
                                        {{ $lot->putaway_started_at->format('d M, H:i') }} → {{ $lot->putaway_ended_at->format('d M, H:i') }} / {{ $lot->putaway_manpower }}
                                    @else
                                        <span class="access-muted">Legacy · not recorded</span>
                                    @endif
                                </td>
                                <td data-label="Status"><span class="access-chip">{{ str($lot->status)->headline() }}</span></td>
                                <td data-label="Next action">
                                    @if ($lot->status === 'staged')
                                        <form method="post" action="{{ route('flow.inventory.putaway', $lot) }}" class="flex gap-2 flex-wrap">
                                            @csrf
                                            @if ((int) ($lot->forgeBatch?->workOrder?->workflow_version ?? 1) >= 2)
                                                <label class="text-xs access-muted">Suggested FG master bin
                                                    <select class="access-input" name="bin_id" required>
                                                        <option value="">Choose active approved bin</option>
                                                        @foreach($masterBins as $bin)
                                                            @if((int) $bin->shelf?->rack?->zone?->tan90_warehouse_id === (int) $lot->warehouse_id)
                                                                <option value="{{ $bin->id }}" @selected((int) $lot->suggested_master_bin_id === (int) $bin->id)>
                                                                    {{ $bin->code }} · {{ $bin->shelf?->rack?->zone?->code }}{{ (int) $lot->suggested_master_bin_id === (int) $bin->id ? ' · Recommended' : '' }}
                                                                </option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                    @if($lot->suggested_master_bin_code)
                                                        <span class="block mt-1">Recommended from same FG / SKU / site, or first eligible warehouse bin.</span>
                                                    @else
                                                        <span class="block mt-1">Choose an active approved bin in this FG warehouse.</span>
                                                    @endif
                                                </label>
                                            @else
                                                <input class="access-input" name="zone" placeholder="Zone" required>
                                                <input class="access-input" name="bin" placeholder="Bin" required>
                                            @endif
                                            <label class="text-xs access-muted">Start<input class="access-input" name="putaway_started_at" type="datetime-local" required></label>
                                            <label class="text-xs access-muted">End<input class="access-input" name="putaway_ended_at" type="datetime-local" required></label>
                                            <input class="access-input" name="putaway_manpower" type="number" min="1" step="1" placeholder="People" required>
                                            <button class="access-btn">Put Away</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="access-muted text-center py-6">No FG inventory received yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $lots->links() }}
        </div>
    </div>
</x-app-layout>
