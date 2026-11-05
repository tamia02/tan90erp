<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Step 4 · Pressure, leak and physical quality</h2><p class="access-muted text-sm">Account for every approved production unit. Failed units never become saleable FG. Recover their mixed PCM only into this work order for another mixing/production round.</p></div>
    @if($wo->status === 'final_qc_pending')
        <form method="post" action="{{ route('forge.final-qc.store', $wo) }}" class="production-panel">@csrf
            <div class="production-step-grid">
                <label class="production-field">Inspection start<input class="access-input" name="started_at" type="datetime-local" required></label>
                <label class="production-field">Inspection end<input class="access-input" name="ended_at" type="datetime-local" required></label>
                <label class="production-field">Inspectors<input class="access-input" name="manpower" type="number" min="1" required></label>
                <label class="production-field">Accepted quantity<input class="access-input" name="accepted_qty" type="number" min="0" step="0.001" required></label>
                <label class="production-field">Rejected quantity<input class="access-input" name="rejected_qty" type="number" min="0" step="0.001" value="0"></label>
                <label class="production-field">Rework quantity<input class="access-input" name="rework_qty" type="number" min="0" step="0.001" value="0"></label>
                <label class="production-field">Leak test<select class="access-input" name="leak_test" required><option value="">Choose</option><option value="pass">Pass</option><option value="fail">Fail</option></select></label>
                <label class="production-field">Pressure test<select class="access-input" name="pressure_test" required><option value="">Choose</option><option value="pass">Pass</option><option value="fail">Fail</option></select></label>
                <label class="production-field">Physical check<select class="access-input" name="physical_check" required><option value="">Choose</option><option value="pass">Pass</option><option value="fail">Fail</option></select></label>
                <label class="production-field">PCM recovered for same order<input class="access-input" name="recovered_pcm_qty" type="number" min="0" step="0.001" value="0"></label>
                <label class="production-field">Decision<select class="access-input" name="result" required><option value="">Choose</option><option value="released">Release accepted units</option><option value="rework">Rework failed units</option><option value="rejected">Reject the run</option></select></label>
            </div>
            <label class="production-field">Inspection results and defects<textarea class="access-input" name="specification_results" rows="3" placeholder="Measurements, visible defects, and observations"></textarea></label>
            <button class="access-btn access-btn-primary">Record QC result</button>
        </form>
    @elseif($wo->status === 'rework')
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">Rework round {{ $wo->rework_generation }} is waiting for the same work order to resume. Recovered PCM stays here and is used in the next filling run. <a class="underline" href="{{ route('forge.modules.workorder', ['step' => 'mixing', 'workOrder' => $wo]) }}">Open PCM making</a>.</div>
    @else
        <p class="access-muted text-sm">The work order reaches this page after approved filling and sealing output is sent to QC.</p>
    @endif
</section>

<section class="access-card p-5 production-panel"><h2 class="text-lg font-bold">Recorded QC results</h2>
    @forelse($qcRecords as $record)
        <div class="rounded-lg border p-4 production-panel text-sm">
            <div><strong>{{ str($record->result)->headline() }}</strong> · {{ $record->accepted_qty }} accepted / {{ $record->rejected_qty }} rejected / {{ $record->rework_qty }} rework {{ $wo->uom }}</div>
            <p class="access-muted">Leak {{ $record->leak_test ?? '—' }} · pressure {{ $record->pressure_test ?? '—' }} · physical {{ $record->physical_check ?? '—' }} · recovered PCM {{ $record->recovered_pcm_qty ?? 0 }} · {{ $record->started_at }} → {{ $record->ended_at }}</p>
            @if(! $record->released_at && $canReleaseQc)
                <form method="post" action="{{ route('forge.final-qc.release', $record->id) }}">@csrf<button class="access-btn access-btn-primary">Approve QC decision</button></form>
            @elseif($record->released_at)
                <span class="access-chip">Approved {{ $record->released_at }}</span>
            @endif
        </div>
    @empty<p class="access-muted text-sm">No QC result recorded yet.</p>@endforelse
</section>
