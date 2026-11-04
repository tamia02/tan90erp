<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Step 2 · Make one PCM solution batch</h2><p class="access-muted text-sm">Mix chemical and water once. A machine pause is downtime, not a second mix. Recovered mixed PCM stays on this work order for rework and never enters warehouse stock.</p></div>
    @if($wo->status === 'material_issued')
        <form method="post" action="{{ route('forge.workorders.start', $wo) }}">@csrf<button class="access-btn access-btn-primary">Start production</button></form>
    @elseif($wo->status === 'rework')
        <form method="post" action="{{ route('forge.workorders.resume-rework', $wo) }}">@csrf<button class="access-btn access-btn-primary">Resume same work order for recovered PCM rework</button></form>
    @endif
    @if($mixingRecords->isEmpty() && in_array($wo->status, ['in_progress','reconciliation'], true))
        <form method="post" action="{{ route('forge.workorders.mixing', $wo) }}" class="production-panel">@csrf
            <div class="production-step-grid">
                <label class="production-field">Mixing method<select class="access-input" name="mixing_type" required><option value="">Choose method</option><option value="manual">Manual</option><option value="mixer">Mixer machine</option></select></label>
                <label class="production-field">Mix start<input class="access-input" name="started_at" type="datetime-local" required></label>
                <label class="production-field">Mix end<input class="access-input" name="ended_at" type="datetime-local" required></label>
                <label class="production-field">Chemical quantity<input class="access-input" name="chemical_qty" type="number" min="0" step="0.001" required></label>
                <label class="production-field">Water quantity<input class="access-input" name="water_qty" type="number" min="0" step="0.001" required></label>
                <label class="production-field">Prepared PCM quantity<input class="access-input" name="pcm_qty" type="number" min="0.001" step="0.001" required></label>
                <label class="production-field">PCM unit<input class="access-input" name="pcm_uom" value="{{ $wo->reusablePcmItem?->unit ?? '' }}" maxlength="20" placeholder="KG" required></label>
                <label class="production-field">Wastage quantity<input class="access-input" name="wastage_qty" type="number" min="0" step="0.001" value="0"></label>
                <label class="production-field">Wastage type<select class="access-input" name="wastage_type"><option value="">None</option><option value="liquid">Liquid · recover into this order</option><option value="solid">Solid · scrap</option></select></label>
                <label class="production-field">Time to fill machine (minutes)<input class="access-input" name="time_to_machine_minutes" type="number" min="0" step="1"></label>
                <label class="production-field">People involved<input class="access-input" name="manpower" type="number" min="1" required></label>
            </div>
            <label class="production-field">Mix notes<textarea class="access-input" name="notes" rows="2" placeholder="Method or deviations"></textarea></label>
            <button class="access-btn access-btn-primary">Save the one PCM mix</button>
        </form>
    @elseif($mixingRecords->isNotEmpty())
        @foreach($mixingRecords as $record)
            <div class="rounded-lg border p-4 text-sm"><strong>PCM mix recorded</strong><p>{{ ucfirst($record->mixing_type ?? 'manual') }} · {{ $record->pcm_qty }} {{ $record->pcm_uom ?? $wo->uom }} prepared · chemical {{ $record->chemical_qty }} + water {{ $record->water_qty }} · {{ $record->manpower }} people</p><p class="access-muted">{{ $record->started_at }} → {{ $record->ended_at }} · {{ $record->wastage_qty }} {{ $record->wastage_type ?? 'loss' }} waste</p></div>
        @endforeach
        <a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => 'filling-sealing', 'workOrder' => $wo]) }}">Continue to filling and sealing →</a>
    @else
        <p class="access-muted text-sm">Complete material issue and start the work order before mixing.</p>
    @endif
</section>
