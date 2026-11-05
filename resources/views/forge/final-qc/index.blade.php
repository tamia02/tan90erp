<x-app-layout>
    @include('access-control._style')
    <style>
        #forge-final-qc .qc-field{display:grid;gap:.35rem;align-content:start;color:#53635b;font-size:.78rem;font-weight:700}
        #forge-final-qc .qc-field .access-input{color:#17211b;font-weight:400}
        #forge-final-qc .qc-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:.75rem}
        #forge-final-qc .qc-table-scroll{overflow:auto;max-width:100%;-webkit-overflow-scrolling:touch}
        #forge-final-qc .qc-table-scroll .access-table{min-width:900px}
        #forge-final-qc .qc-table-scroll th,#forge-final-qc .qc-table-scroll td{white-space:nowrap}
        #forge-final-qc .qc-reference{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem}
        #forge-final-qc :is(input,select,textarea,button,a):focus-visible{outline:3px solid #65a98a;outline-offset:2px}
        #forge-final-qc .access-btn{min-height:2.75rem}
    </style>
    <div id="forge-final-qc" class="access-shell max-w-7xl mx-auto space-y-5" data-listing-ignore>
        <div class="access-top p-5">
            <div class="text-sm access-muted">Production / Step 04 of 06</div>
            <h1 class="text-2xl font-bold">Pressure & Leak Test</h1>
            @include('forge._exports', ['permission'=>'forge.finalqc.export', 'exportRoute'=>'forge.final-qc.export'])
            <p class="access-muted text-sm mt-2">Inspect accepted, rejected and rework units, then release the QC result to create the FG batch and consumption record.</p>
            @if($focusedWorkOrderId)<a class="access-btn mt-3" href="{{ route('forge.final-qc.index') }}">View all QC work orders</a>@endif
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm" role="status" aria-live="polite">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif

        <div class="qc-metrics" aria-label="Final quality workload summary">
            <div class="access-card p-4"><div class="access-muted text-sm">Awaiting inspection</div><div class="text-xl font-bold">{{ $pending->count() }}</div></div>
            <div class="access-card p-4"><div class="access-muted text-sm">Recorded QC results</div><div class="text-xl font-bold">{{ $results->total() }}</div></div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Pending Final QC</h2>
            <p class="access-muted text-sm mb-3">Inspect each production round before finished goods are released. Record measured quantities and test results from the physical inspection; accepted + rejected + rework must account for this round's output. If rework is needed, release the result, resume the same work order, and complete QC again.</p>
            <details class="text-sm mb-4">
                <summary class="font-semibold cursor-pointer">What each QC field means</summary>
                <ul class="access-muted mt-2 space-y-1 pl-5 list-disc">
                    <li><strong>Accepted:</strong> units that pass inspection and can be released.</li>
                    <li><strong>Rejected:</strong> failed units that cannot enter saleable finished-goods stock.</li>
                                    <li><strong>Rework:</strong> units sent through another run and QC round on the same work order.</li>
                    <li><strong>Leak and pressure tests:</strong> record the applicable package integrity checks.</li>
                    <li><strong>Physical check:</strong> inspect pouch condition, seal and visible defects before FG release.</li>
                    <li><strong>Inspection times and inspectors:</strong> preserve the quality activity and accountability trace.</li>
                                    <li><strong>Recovered PCM:</strong> stays with this work order for same-order rework; it is never posted to warehouse stock.</li>
                                    <li><strong>Specification results:</strong> capture measured values, defects, and observations supporting the disposition.</li>
                </ul>
            </details>
            <div class="space-y-4">
                @forelse ($pending as $wo)
                    <form class="border rounded p-3 space-y-2" style="border-color:#dfe7e2" method="post" action="{{ route('forge.final-qc.store', $wo) }}">
                        @csrf
                        <div class="font-semibold"><span class="qc-reference">{{ $wo->wo_number }}</span><br>{{ $wo->finishedGood?->name }} <span class="access-muted">· Reconciled good quantity: {{ $wo->good_qty }} {{ $wo->uom }}</span></div>
                        <div class="access-grid">
                            @if ($wo->inventory_fg_item_id)
                                <label class="qc-field">Inspection start<input class="access-input" name="started_at" type="datetime-local" required><span class="access-muted font-normal">When physical checks began; used for the QC time trace.</span></label>
                                <label class="qc-field">Inspection end<input class="access-input" name="ended_at" type="datetime-local" required><span class="access-muted font-normal">When checks ended; must be after the start time.</span></label>
                                <label class="qc-field">Inspectors<input class="access-input" name="manpower" type="number" min="1" step="1" placeholder="Number of people" required><span class="access-muted font-normal">People who performed or witnessed this inspection.</span></label>
                            @endif
                            <label class="qc-field">Accepted quantity<input class="access-input" name="accepted_qty" type="number" step="0.001" min="0" placeholder="0.000" required><span class="access-muted font-normal">Units passing inspection and eligible for release.</span></label>
                            <label class="qc-field">Rejected quantity<input class="access-input" name="rejected_qty" type="number" step="0.001" min="0" placeholder="0.000"><span class="access-muted font-normal">Units that fail and must not enter saleable FG stock.</span></label>
                            <label class="qc-field">Rework quantity<input class="access-input" name="rework_qty" type="number" step="0.001" min="0" placeholder="0.000"><span class="access-muted font-normal">Units sent back for correction and another quality check.</span></label>
                            @if ($wo->inventory_fg_item_id)
                                <label class="qc-field">Leak test<select class="access-input" name="leak_test" required><option value="">Select result</option><option value="pass">Pass</option><option value="fail">Fail</option></select><span class="access-muted font-normal">Confirms the sealed unit has no leakage.</span></label>
                                <label class="qc-field">Pressure test<select class="access-input" name="pressure_test" required><option value="">Select result</option><option value="pass">Pass</option><option value="fail">Fail</option></select><span class="access-muted font-normal">Confirms the unit meets its pressure test criteria.</span></label>
                                @if((int) $wo->workflow_version >= 2)
                                    <label class="qc-field">Physical check<select class="access-input" name="physical_check" required><option value="">Select result</option><option value="pass">Pass</option><option value="fail">Fail</option></select><span class="access-muted font-normal">Check package condition, seal and visible defects before release.</span></label>
                                @endif
                                <label class="qc-field">PCM recovered for this order<input class="access-input" name="recovered_pcm_qty" type="number" step="0.001" min="0" placeholder="0.000"><span class="access-muted font-normal">Recorded for same-order rework. Mixed PCM cannot be stored or credited to a warehouse.</span></label>
                            @endif
                            <label class="qc-field">QC disposition<select class="access-input" name="result" required>
                                <option value="released">Release</option><option value="rework">Rework</option><option value="rejected">Reject</option>
                            </select><span class="access-muted font-normal">Release only after required checks and quantities are reconciled.</span></label>
                        </div>
                        <label class="qc-field">Inspection notes and specification results<textarea class="access-input" name="specification_results" rows="3" placeholder="Record measured results, defects, or observations"></textarea></label>
                        <button class="access-btn access-btn-primary">Record Final QC</button>
                    </form>
                @empty
                    <p class="access-muted text-sm">Nothing waiting for final QC.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Results</h2>
            <div class="qc-table-scroll" role="region" aria-label="Final QC results" tabindex="0">
                <table class="access-table">
                    <thead><tr><th>Work Order</th><th>QC time / people</th><th>Accepted</th><th>Rejected</th><th>Rework</th><th>Physical</th><th>Result</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($results as $result)
                            <tr>
                                <td class="qc-reference">{{ $result->workOrder?->wo_number }}</td>
                                <td>
                                    @if ($result->started_at && $result->ended_at)
                                        {{ $result->started_at->format('d M, H:i') }} → {{ $result->ended_at->format('d M, H:i') }} / {{ $result->manpower }}
                                    @else
                                        <span class="access-muted">Legacy · not recorded</span>
                                    @endif
                                </td>
                                <td>{{ $result->accepted_qty }}</td>
                                <td>{{ $result->rejected_qty }}</td>
                                <td>{{ $result->rework_qty }}</td>
                                <td>{{ $result->physical_check ? str($result->physical_check)->headline() : 'Legacy · not recorded' }}</td>
                                <td><span class="access-chip">{{ str($result->result)->headline() }}</span></td>
                                <td>
                                    @if (! $result->released_at)
                                        <form method="post" action="{{ route('forge.final-qc.release', $result) }}">
                                            @csrf
                                            <button class="access-btn access-btn-primary">Release</button>
                                        </form>
                                    @else
                                        <span class="access-muted text-sm">Released {{ $result->released_at->format('d M, H:i') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="access-muted text-center py-6">No final QC results yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $results->links() }}
        </div>
    </div>
</x-app-layout>
