<x-app-layout>
    @include('access-control._style')
    @php($wo = $batch->workOrder)
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Batch Genealogy / {{ $batch->batch_number }}</div>
            <h1 class="text-2xl font-bold">Batch {{ $batch->batch_number }} — {{ $wo->finishedGood?->name }}</h1>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Backward Trace</h2>
            <div class="space-y-3">
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Finished Batch</strong>
                    <div class="access-muted text-sm">{{ $batch->qty }} {{ $batch->uom }} · released {{ $batch->released_at?->format('d M Y, H:i') }}</div>
                </div>
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Final QC</strong>
                    @if ($wo->finalQcResult)
                        <div class="access-muted text-sm">{{ $wo->finalQcResult->result }} — accepted {{ $wo->finalQcResult->accepted_qty }}, rejected {{ $wo->finalQcResult->rejected_qty }}, rework {{ $wo->finalQcResult->rework_qty }}</div>
                    @else
                        <div class="access-muted text-sm">No final QC record.</div>
                    @endif
                </div>
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Production Entries</strong>
                    @forelse ($wo->productionEntries as $entry)
                        <div class="access-muted text-sm">good {{ $entry->good_qty }}, rework {{ $entry->rework_qty }}, rejected {{ $entry->rejected_qty }} — {{ $entry->status }}</div>
                    @empty
                        <div class="access-muted text-sm">None.</div>
                    @endforelse
                </div>
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Job Cards</strong>
                    @forelse ($wo->jobCards as $card)
                        <div class="access-muted text-sm">{{ $card->sequence }}. {{ $card->operation_name }} — {{ $card->machine?->name ?? 'no machine' }} — {{ $card->operator?->name ?? 'unassigned' }} — {{ $card->status }}</div>
                    @empty
                        <div class="access-muted text-sm">None.</div>
                    @endforelse
                </div>
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Work Order</strong>
                    <div class="access-muted text-sm">{{ $wo->wo_number }} — released recipe/BOM/routing: {{ $wo->recipe?->code ?? '—' }} / {{ $wo->bom?->code ?? '—' }} / {{ $wo->routing?->code ?? '—' }}</div>
                </div>
                <div class="border rounded p-3" style="border-color:#dfe7e2">
                    <strong>Issued Raw Lots</strong>
                    @forelse ($wo->materialIssues as $issue)
                        <div class="access-muted text-sm">{{ $issue->item_name }} ({{ $issue->item_code }}) — lot {{ $issue->lot_number ?? '—' }} — {{ $issue->qty }} {{ $issue->uom }} — {{ $issue->movement_type }}</div>
                    @empty
                        <div class="access-muted text-sm">No material issues recorded — this work order's raw-lot trace is not yet linked to a GRN lot from Origin.</div>
                    @endforelse
                </div>
                @if ($wo->qualityHolds->isNotEmpty())
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <strong>IPQC Checkpoints</strong>
                        @foreach ($wo->qualityHolds as $hold)
                            <div class="access-muted text-sm">{{ $hold->checkpoint }} — {{ $hold->result ?? 'pending' }} ({{ $hold->status }})</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <p class="access-muted text-sm">Forward trace (FG location → allocation → dispatch → customer/POD) continues in Flow (Space 06) once this batch is received into finished-goods inventory.</p>
    </div>
</x-app-layout>
