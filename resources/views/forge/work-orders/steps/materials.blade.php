<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Approved recipe and real warehouse stock</h2><p class="access-muted text-sm">The exact Inventory FG SKU controls the RM/PM quantities below. Request only material in its approved recipe; issuing records FIFO lots and reduces the selected RM warehouse ledger.</p></div>
    @if($wo->status === 'draft')
        <form method="post" action="{{ route('forge.workorders.release', $wo) }}">@csrf<button class="access-btn access-btn-primary">Release this work order</button></form>
    @endif
    @if($bomItems->isEmpty())
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">No Inventory recipe lines exist for this FG SKU. Add and approve the real RM/PM recipe before issuing stock.</div>
    @else
        <div class="overflow-x-auto"><table class="access-table"><thead><tr><th>Material</th><th>Per FG</th><th>Required for {{ $wo->target_qty }} FG</th><th>RM stock at this warehouse</th><th>Already issued</th></tr></thead><tbody>
        @foreach($bomItems as $line)
            @php($item = $line->component)
            <tr><td>{{ $item?->code ?: '#'.$line->component_item_id }} · {{ $item?->safeDisplayName() }}</td><td>{{ $line->qty_per_unit }} {{ $item?->unit }}</td><td>{{ number_format((float)$line->qty_per_unit * (float)$wo->target_qty, 3) }} {{ $item?->unit }}</td><td>{{ isset($materialStock[$line->component_item_id]) ? number_format((float)$materialStock[$line->component_item_id]['final'], 3) : '—' }} {{ $item?->unit }}</td><td>{{ number_format((float)$wo->materialIssues->where('inventory_item_id', $line->component_item_id)->where('movement_type', 'issue')->sum('qty'), 3) }} {{ $item?->unit }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</section>

@if((int)$wo->workflow_version >= 2 && $bomItems->isNotEmpty())
<section class="access-card p-5 production-panel">
    <h2 class="text-lg font-bold">Step 1 · Request Items and FIFO handover</h2>
    @if(in_array($wo->status, ['released','material_issued','in_progress','reconciliation','final_qc_pending','bundle_pending'], true))
        <form method="post" action="{{ route('forge.workorders.material-requests.store', $wo) }}" class="production-panel">@csrf
            <p class="access-muted text-sm">Enter a full, partial, or top-up quantity for at least one recipe material. Explain extra quantities above the approved recipe.</p>
            @foreach($bomItems as $index => $line)
                <div class="production-step-grid border-b pb-3">
                    <div class="text-sm"><strong>{{ $line->component?->code ?: '#'.$line->component_item_id }}</strong><span class="block access-muted">{{ $line->component?->safeDisplayName() }} · {{ $line->qty_per_unit }} {{ $line->component?->unit }} per FG</span></div>
                    <input type="hidden" name="lines[{{ $index }}][inventory_item_id]" value="{{ $line->component_item_id }}">
                    <label class="production-field">Quantity to request<input class="access-input" type="number" step="0.001" min="0.001" name="lines[{{ $index }}][qty]" placeholder="{{ number_format((float)$line->qty_per_unit * (float)$wo->target_qty, 3, '.', '') }}"></label>
                    <label class="production-field">Extra material reason<input class="access-input" name="lines[{{ $index }}][extra_reason]" placeholder="Only if above recipe quantity"></label>
                </div>
            @endforeach
            <button class="access-btn access-btn-primary">Send material request</button>
        </form>
    @endif
    <div class="production-panel">
        @forelse($materialRequests as $materialRequest)
            <div class="border rounded-lg p-4 production-panel">
                <h3 class="font-semibold">Request #{{ $materialRequest->id }} · {{ str($materialRequest->status)->headline() }}</h3>
                @foreach($requestLines->where('material_request_id', $materialRequest->id) as $line)<p class="text-sm">{{ $line->code ?: $line->name }} · {{ $line->qty }} {{ $line->unit }} @if($line->extra_reason) · {{ $line->extra_reason }} @endif</p>@endforeach
                @if($materialRequest->status === 'requested')
                    <form method="post" action="{{ route('forge.workorders.material-requests.issue', [$wo, $materialRequest->id]) }}" class="production-panel">@csrf
                        <div class="production-step-grid"><label class="production-field">Handover start<input class="access-input" name="handover_started_at" type="datetime-local" required></label><label class="production-field">Handover end<input class="access-input" name="handover_ended_at" type="datetime-local" required></label><label class="production-field">People involved<input class="access-input" name="manpower" type="number" min="1" required></label></div>
                        <button class="access-btn access-btn-primary">Approve and issue FIFO stock</button>
                    </form>
                @else
                    <p class="access-muted text-sm">Handover {{ $materialRequest->handover_started_at }} → {{ $materialRequest->handover_ended_at }} · {{ $materialRequest->manpower }} people</p>
                @endif
            </div>
        @empty
            <p class="access-muted text-sm">No material request recorded yet.</p>
        @endforelse
    </div>
</section>
@endif

<section class="access-card p-5 production-panel"><h2 class="font-bold">FIFO issue history</h2>
    @forelse($wo->materialIssues as $issue)<p class="text-sm">{{ str($issue->movement_type)->headline() }} · {{ $issue->item_code }} · {{ $issue->qty }} {{ $issue->uom }} · {{ $issue->lot_number ?: 'See FIFO allocation in inventory ledger' }}</p>@empty<p class="access-muted text-sm">No inventory movement yet.</p>@endforelse
    @if($wo->status === 'material_issued')<a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => 'mixing', 'workOrder' => $wo]) }}">Continue to PCM making →</a>@endif
    <div class="flex flex-wrap gap-2"><a class="access-btn" href="{{ route('forge.plans.index') }}">Production plans</a><a class="access-btn" href="{{ route('forge.workorders.create', ['plan' => $wo->production_plan_id]) }}">Create another work order</a></div>
</section>
