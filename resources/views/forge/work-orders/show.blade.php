<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between flex-wrap gap-3">
            <div>
                <div class="text-sm access-muted">Forge / Work Orders / {{ $wo->wo_number }}</div>
                <h1 class="text-2xl font-bold">{{ $wo->wo_number }} — {{ $wo->finishedGood?->name }}</h1>
            </div>
            <span class="access-chip">{{ str($wo->status)->replace('_', ' ')->headline() }}</span>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-grid">
            <div class="access-card p-4">
                <div class="access-muted text-sm">Target / Good / Rework / Rejected</div>
                <div class="font-bold">{{ $wo->target_qty }} / {{ $wo->good_qty }} / {{ $wo->rework_qty }} / {{ $wo->rejected_qty }} {{ $wo->uom }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">BOM / Recipe / Routing</div>
                <div class="font-bold">{{ $wo->bom?->code ?? '—' }} / {{ $wo->recipe?->code ?? '—' }} / {{ $wo->routing?->code ?? '—' }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Batch Number</div>
                <div class="font-bold">{{ $wo->batch_number ?? '—' }}</div>
            </div>
        </div>

        <div class="access-card p-5 flex flex-wrap gap-2">
            <h2 class="font-bold w-full mb-1">Actions</h2>
            @if ($wo->status === 'draft')
                <form method="post" action="{{ route('forge.workorders.release', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Release</button></form>
            @endif
            @if ($wo->status === 'released')
                <form method="post" action="{{ route('forge.workorders.reserve-material', $wo) }}">{{ csrf_field() }}<button class="access-btn">Reserve Material</button></form>
            @endif
            @if ($wo->status === 'material_issued')
                <form method="post" action="{{ route('forge.workorders.start', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Start Production</button></form>
            @endif
            @if ($wo->status === 'reconciliation')
                <form method="post" action="{{ route('forge.workorders.send-to-final-qc', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Send to Final QC</button></form>
            @endif
            @if (in_array($wo->status, ['released_to_fg', 'rejected']))
                <form method="post" action="{{ route('forge.workorders.close', $wo) }}">{{ csrf_field() }}<button class="access-btn">Close Work Order</button></form>
            @endif
        </div>

        @if ($wo->status === 'material_reserved')
            <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.workorders.issue-material', $wo) }}">
                @csrf
                <h2 class="font-bold">Issue Material</h2>
                <div class="access-grid">
                    <input class="access-input" name="lines[0][item_code]" placeholder="Item code" required>
                    <input class="access-input" name="lines[0][item_name]" placeholder="Item name" required>
                    <input class="access-input" name="lines[0][lot_number]" placeholder="Lot number">
                    <input class="access-input" name="lines[0][qty]" type="number" step="0.001" min="0.001" placeholder="Quantity" required>
                    <input class="access-input" name="lines[0][uom]" placeholder="UOM" required>
                </div>
                <button class="access-btn access-btn-primary">Issue</button>
            </form>
        @endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Job Cards</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>#</th><th>Operation</th><th>Machine</th><th>Operator</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($wo->jobCards as $card)
                            <tr>
                                <td>{{ $card->sequence }}</td>
                                <td>{{ $card->operation_name }}</td>
                                <td>{{ $card->machine?->name ?? '—' }}</td>
                                <td>{{ $card->operator?->name ?? '—' }}</td>
                                <td><span class="access-chip">{{ str($card->status)->headline() }}</span></td>
                                <td class="flex gap-2">
                                    @if ($card->status === 'pending')
                                        <form method="post" action="{{ route('forge.job-cards.start', $card) }}">{{ csrf_field() }}<button class="access-btn">Start</button></form>
                                    @endif
                                    @if ($card->status === 'started')
                                        <form method="post" action="{{ route('forge.job-cards.pause', $card) }}">{{ csrf_field() }}<button class="access-btn">Pause</button></form>
                                        <form method="post" action="{{ route('forge.job-cards.complete', $card) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Complete</button></form>
                                    @endif
                                    @if ($card->status === 'paused')
                                        <form method="post" action="{{ route('forge.job-cards.resume', $card) }}">{{ csrf_field() }}<button class="access-btn">Resume</button></form>
                                        <form method="post" action="{{ route('forge.job-cards.complete', $card) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Complete</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No job cards — this work order has no routing attached.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if (in_array($wo->status, ['in_progress', 'reconciliation']))
            <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.workorders.record-production', $wo) }}">
                @csrf
                <h2 class="font-bold">Record Production</h2>
                <div class="access-grid">
                    <select class="access-input" name="job_card_id">
                        <option value="">No specific job card</option>
                        @foreach ($wo->jobCards as $card)
                            <option value="{{ $card->id }}">{{ $card->operation_name }}</option>
                        @endforeach
                    </select>
                    <input class="access-input" name="good_qty" type="number" step="0.001" min="0" placeholder="Good qty" required>
                    <input class="access-input" name="rework_qty" type="number" step="0.001" min="0" placeholder="Rework qty">
                    <input class="access-input" name="rejected_qty" type="number" step="0.001" min="0" placeholder="Rejected qty">
                </div>
                <button class="access-btn access-btn-primary">Record</button>
            </form>
        @endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Production Entries</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Good</th><th>Rework</th><th>Rejected</th><th>Status</th><th>Recorded By</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($wo->productionEntries as $entry)
                            <tr>
                                <td>{{ $entry->good_qty }}</td>
                                <td>{{ $entry->rework_qty }}</td>
                                <td>{{ $entry->rejected_qty }}</td>
                                <td><span class="access-chip">{{ str($entry->status)->headline() }}</span></td>
                                <td>{{ $entry->recorder?->name }}</td>
                                <td>
                                    @if ($entry->status === 'draft')
                                        <form method="post" action="{{ route('forge.production.approve', $entry) }}">{{ csrf_field() }}<button class="access-btn">Approve</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No production entries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Material Issues</h2>
                <div class="space-y-2">
                    @forelse ($wo->materialIssues as $issue)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $issue->item_name }} ({{ $issue->item_code }}) — {{ $issue->qty }} {{ $issue->uom }}
                            <span class="access-muted">· {{ $issue->movement_type }} · lot {{ $issue->lot_number ?? '—' }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No material movements yet.</p>
                    @endforelse
                </div>
            </section>
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Quality Holds</h2>
                <div class="space-y-2">
                    @forelse ($wo->qualityHolds as $hold)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $hold->checkpoint }} — <span class="access-chip">{{ $hold->status }}</span>
                            <span class="access-muted">result: {{ $hold->result ?? '—' }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No IPQC checkpoints recorded yet.</p>
                    @endforelse
                </div>
            </section>
        </div>

        @if ($wo->batch)
            <div class="access-card p-5">
                <h2 class="font-bold mb-2">Finished Batch</h2>
                <a class="access-btn access-btn-primary" href="{{ route('forge.batches.show', $wo->batch) }}">
                    View genealogy for batch {{ $wo->batch->batch_number }}
                </a>
            </div>
        @endif
    </div>
</x-app-layout>
