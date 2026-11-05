@php($lot = $wo->batch?->inventoryLot)
<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Step 6 · FG bin putaway</h2><p class="access-muted text-sm">Receive the counted QC-approved batch once. Then choose an active approved master bin inside this work order's FG warehouse. The lot becomes available for sales-order allocation after putaway.</p></div>
    @if(! $wo->batch)
        <p class="access-muted text-sm">No FG batch exists yet. Complete QC and confirm physical bundles first.</p>
        <a class="access-btn" href="{{ route('forge.modules.workorder', ['step' => 'consumption-fg', 'workOrder' => $wo]) }}">Open bundle count</a>
    @elseif(! $lot)
        <div class="rounded-lg border p-4 text-sm"><strong>Released batch {{ $wo->batch->batch_number }}</strong><p>{{ $wo->batch->qty }} {{ $wo->batch->uom }} · FG warehouse #{{ $wo->fg_warehouse_id }}</p></div>
        @if($canReceiveFg)<form method="post" action="{{ route('flow.inventory.receive', $wo->batch) }}">@csrf<button class="access-btn access-btn-primary">Receive FG batch into staging</button></form>@endif
    @else
        <div class="production-step-grid">
            <div class="rounded-lg border p-3 text-sm"><strong>Lot</strong><span class="block">{{ $lot->lot_number }} · {{ str($lot->status)->headline() }}</span></div>
            <div class="rounded-lg border p-3 text-sm"><strong>Quantity</strong><span class="block">{{ $lot->qty_received }} received · {{ $lot->qty_available }} available / {{ $lot->qty_allocated }} allocated {{ $lot->uom }}</span></div>
            <div class="rounded-lg border p-3 text-sm"><strong>Warehouse and bin</strong><span class="block">{{ $lot->warehouse }} · {{ $lot->zone ?? 'Zone pending' }} / {{ $lot->bin ?? 'Bin pending' }}</span></div>
        </div>
        @if($lot->status === 'staged' && $canPutawayFg)
            @if($eligibleFgBins->isEmpty())<div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">No active approved master bin exists in this FG warehouse. Create and approve a zone, rack, shelf and bin in Master Data before putaway.</div>@endif
            <form method="post" action="{{ route('flow.inventory.putaway', $lot) }}" class="production-panel">@csrf
                <div class="production-step-grid">
                    <label class="production-field">Approved FG bin<select class="access-input" name="bin_id" required><option value="">Choose bin in mapped warehouse</option>@foreach($eligibleFgBins as $bin)<option value="{{ $bin->id }}">{{ $bin->code }} · {{ $bin->shelf?->rack?->zone?->code }}</option>@endforeach</select></label>
                    <label class="production-field">Putaway start<input class="access-input" name="putaway_started_at" type="datetime-local" required></label>
                    <label class="production-field">Putaway end<input class="access-input" name="putaway_ended_at" type="datetime-local" required></label>
                    <label class="production-field">People involved<input class="access-input" name="putaway_manpower" type="number" min="1" required></label>
                </div>
                <button class="access-btn access-btn-primary" @disabled($eligibleFgBins->isEmpty())>Put away FG lot</button>
            </form>
        @elseif($lot->status === 'available')
            <p class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm">FG is in bin {{ $lot->bin }} and available for allocation. Received {{ $lot->received_at?->format('d M Y, H:i') }}; putaway {{ $lot->putaway_started_at?->format('d M Y, H:i') }} → {{ $lot->putaway_ended_at?->format('d M Y, H:i') }} by {{ $lot->putaway_manpower }} person(s).</p>
            @if($wo->status === 'released_to_fg' && $canCloseWorkOrder)<form method="post" action="{{ route('forge.workorders.close', $wo) }}">@csrf<button class="access-btn access-btn-primary">Close completed work order</button></form>@endif
        @endif
    @endif
</section>
