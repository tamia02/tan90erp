@php($cycleNo = (int)$wo->rework_generation + 1)
@php($cycleRecords = $processRecords->where('cycle_no', $cycleNo))
@php($hasFilling = $cycleRecords->contains(fn ($record) => $record->step === 'filling'))
@php($hasSealing = $cycleRecords->contains(fn ($record) => $record->step === 'sealing'))
@php($hasContinuousFeed = $processRecords->contains(fn ($record) => $record->continuous_feed_qty !== null))
<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Step 3 · Filling then sealing</h2><p class="access-muted text-sm">Feed the prepared PCM solution once for this work order. Record filling output, then sealing output. Pauses and breakdowns belong to this run; recovered PCM can be reused only on the same order.</p></div>
    @if($mixingRecords->isEmpty() && (int)$wo->workflow_version >= 2)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4"><strong>Complete PCM making first</strong><p class="text-sm">One recorded PCM mix is required before production starts.</p><a class="access-btn mt-2" href="{{ route('forge.modules.workorder', ['step' => 'mixing', 'workOrder' => $wo]) }}">Open PCM making</a></div>
    @elseif(in_array($wo->status, ['in_progress','reconciliation'], true) && ! $hasSealing)
        @php($operation = $hasFilling ? 'sealing' : 'filling')
        <form method="post" action="{{ route('forge.workorders.process', $wo) }}" class="production-panel">@csrf<input type="hidden" name="step" value="{{ $operation }}">
            <h3 class="font-semibold">Record {{ ucfirst($operation) }} · round {{ $cycleNo }}</h3>
            <div class="production-step-grid">
                <label class="production-field">Machine mode<select class="access-input" name="machine_type" required><option value="manual">Manual</option><option value="automatic">Automatic</option></select></label>
                <label class="production-field">Machine<select class="access-input" name="machine_id"><option value="">Manual operation</option>@foreach($machines->where('status', 'active') as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select></label>
                <label class="production-field">{{ ucfirst($operation) }} start<input class="access-input" type="datetime-local" name="started_at" required></label>
                <label class="production-field">{{ ucfirst($operation) }} end<input class="access-input" type="datetime-local" name="ended_at" required></label>
                @if($operation === 'filling' && ! $hasContinuousFeed)<label class="production-field">One continuous PCM feed ({{ $mixingRecords->first()?->pcm_uom ?? $wo->uom }})<input class="access-input" type="number" name="continuous_feed_qty" min="0.001" step="0.001" required><span class="access-muted font-normal">Enter the total solution poured into this order once.</span></label>@endif
                @if($operation === 'filling' && $recoveredPcmAvailable > 0)<label class="production-field">Recovered PCM reused ({{ $mixingRecords->first()?->pcm_uom ?? $wo->uom }})<input class="access-input" type="number" name="recovered_pcm_used_qty" min="0.001" max="{{ $recoveredPcmAvailable }}" step="0.001" required><span class="access-muted font-normal">Same order only; never warehouse stock.</span></label>@endif
                <label class="production-field">Good units<input class="access-input" type="number" name="good_qty" min="0" step="0.001" required></label>
                <label class="production-field">Rejected units<input class="access-input" type="number" name="rejected_qty" min="0" step="0.001" value="0"></label>
                <label class="production-field">Wastage quantity<input class="access-input" type="number" name="wastage_qty" min="0" step="0.001" value="0"></label>
                <label class="production-field">Wastage reason<input class="access-input" name="wastage_reason" maxlength="255" placeholder="Required if wastage > 0"></label>
                <label class="production-field">Recoverable PCM<input class="access-input" type="number" name="recovered_pcm_qty" min="0" step="0.001" value="0"></label>
                <label class="production-field">Power consumed (kWh)<input class="access-input" type="number" name="power_kwh" min="0" step="0.001"></label>
                <label class="production-field">Idle minutes<input class="access-input" type="number" name="idle_minutes" min="0" step="1" value="0"></label>
                <label class="production-field">Idle reason<input class="access-input" name="idle_reason" maxlength="255"></label>
                <label class="production-field">Breakdown minutes<input class="access-input" type="number" name="breakdown_minutes" min="0" step="1" value="0"></label>
                <label class="production-field">Breakdown reason<input class="access-input" name="breakdown_reason" maxlength="255"></label>
                <label class="production-field">People involved<input class="access-input" name="manpower" type="number" min="1" required></label>
            </div>
            <label class="production-field">Process notes<textarea class="access-input" name="notes" rows="2"></textarea></label>
            <button class="access-btn access-btn-primary">Save {{ $operation }} operation</button>
        </form>
    @endif

    <div class="production-panel"><h3 class="font-semibold">Recorded operations</h3>
        @forelse($processRecords as $record)<p class="text-sm rounded-lg border p-3">Round {{ $record->cycle_no }} · {{ ucfirst($record->step) }} · {{ $record->good_qty }} good / {{ $record->rejected_qty }} rejected · {{ $record->started_at }} → {{ $record->ended_at }} · {{ $record->manpower }} people @if($record->continuous_feed_qty) · continuous PCM {{ $record->continuous_feed_qty }}@endif</p>@empty<p class="access-muted text-sm">No filling or sealing recorded yet.</p>@endforelse
    </div>
</section>

@if($hasSealing || $wo->productionEntries->isNotEmpty())
<section class="access-card p-5 production-panel"><h2 class="text-lg font-bold">Approve counted production output</h2>
    @if(in_array($wo->status, ['in_progress','reconciliation'], true) && $hasSealing && $wo->productionEntries->where('cycle_no', $cycleNo)->isEmpty())
        <form method="post" action="{{ route('forge.workorders.record-production', $wo) }}" class="production-panel">@csrf
            <div class="production-step-grid"><label class="production-field">Good sealed units<input class="access-input" name="good_qty" type="number" min="0" step="0.001" required></label><label class="production-field">Rework units<input class="access-input" name="rework_qty" type="number" min="0" step="0.001" value="0"></label><label class="production-field">Rejected units<input class="access-input" name="rejected_qty" type="number" min="0" step="0.001" value="0"></label></div>
            <button class="access-btn access-btn-primary">Record production count</button>
        </form>
    @endif
    @foreach($wo->productionEntries as $entry)
        <div class="rounded-lg border p-3 text-sm flex flex-wrap justify-between gap-2"><span>{{ $entry->good_qty }} good / {{ $entry->rework_qty }} rework / {{ $entry->rejected_qty }} rejected {{ $wo->uom }} · {{ str($entry->status)->headline() }}</span>@if($entry->status === 'draft')<form method="post" action="{{ route('forge.production.approve', $entry) }}">@csrf<button class="access-btn access-btn-primary">Approve count</button></form>@endif</div>
    @endforeach
    @if($wo->status === 'reconciliation' && (float)$wo->good_qty > 0)
        <form method="post" action="{{ route('forge.workorders.send-to-final-qc', $wo) }}">@csrf<button class="access-btn access-btn-primary">Send to QC →</button></form>
    @endif
</section>
@endif
