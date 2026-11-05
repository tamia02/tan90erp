<section class="access-card p-5 production-panel">
    <div><h2 class="text-lg font-bold">Step 5 · Physical bundle count</h2><p class="access-muted text-sm">Count the actual packed bundles and loose accepted units. The total must equal QC accepted quantity. The approved Inventory BOM then calculates RM/PM consumption, returns unused issued material, and posts one FG batch.</p></div>
    @if($wo->status === 'bundle_pending')
        <div class="production-step-grid"><div class="rounded-lg border p-3 text-sm"><strong>QC accepted</strong><span class="block">{{ $wo->finalQcResult?->accepted_qty }} {{ $wo->uom }}</span></div><div class="rounded-lg border p-3 text-sm"><strong>Defective / rework</strong><span class="block">{{ (float)($wo->finalQcResult?->rejected_qty ?? 0) + (float)($wo->finalQcResult?->rework_qty ?? 0) }} {{ $wo->uom }}</span></div></div>
        <form method="post" action="{{ route('forge.workorders.bundle.confirm', $wo) }}" class="production-panel">@csrf
            <div class="production-step-grid">
                <label class="production-field">Number of physical bundles<input class="access-input" name="bundle_count" type="number" min="1" step="1" value="{{ old('bundle_count') }}" required></label>
                <label class="production-field">Accepted units per bundle<input class="access-input" name="units_per_bundle" type="number" min="0.001" step="0.001" value="{{ old('units_per_bundle') }}" required></label>
                <label class="production-field">Loose accepted units<input class="access-input" name="loose_units" type="number" min="0" step="0.001" value="{{ old('loose_units', 0) }}" required></label>
                <label class="production-field">Defective units<input class="access-input" name="defective_qty" type="number" min="0" step="0.001" value="{{ old('defective_qty', (float)($wo->finalQcResult?->rejected_qty ?? 0) + (float)($wo->finalQcResult?->rework_qty ?? 0)) }}" required></label>
                <label class="production-field">Defective disposition<select class="access-input" name="defective_disposition" required><option value="">Choose</option><option value="hold">Hold for investigation</option><option value="rework">Rework</option><option value="scrap">Scrap</option></select></label>
                <label class="production-field">Bundle start<input class="access-input" name="started_at" type="datetime-local" required></label>
                <label class="production-field">Bundle end<input class="access-input" name="ended_at" type="datetime-local" required></label>
                <label class="production-field">People involved<input class="access-input" name="manpower" type="number" min="1" required></label>
            </div>
            <p class="text-sm access-muted">Formula: bundles × units per bundle + loose units = {{ $wo->finalQcResult?->accepted_qty }} {{ $wo->uom }}. RM/PM consumption comes from the approved Inventory BOM; no duplicate stock posting is allowed. If an issued material is short, request a top-up on Step 1 and return here.</p>
            <button class="access-btn access-btn-primary">Confirm real bundles and post FG once</button>
        </form>
    @elseif($consumptionRecord)
        <div class="production-step-grid">
            <div class="rounded-lg border p-3 text-sm"><strong>Physical pack-out</strong><span class="block">{{ $consumptionRecord->bundle_count }} bundles × {{ $consumptionRecord->units_per_bundle }} + {{ $consumptionRecord->loose_units }} loose {{ $wo->uom }}</span></div>
            <div class="rounded-lg border p-3 text-sm"><strong>FG batch</strong><span class="block">{{ $wo->batch?->batch_number }} · {{ $wo->batch?->qty }} {{ $wo->batch?->uom }}</span></div>
            <div class="rounded-lg border p-3 text-sm"><strong>Defective disposition</strong><span class="block">{{ $consumptionRecord->defective_qty }} {{ $wo->uom }} · {{ str($consumptionRecord->defective_disposition)->headline() }}</span></div>
            <div class="rounded-lg border p-3 text-sm"><strong>Counted by</strong><span class="block">{{ $consumptionRecord->manpower }} people · {{ $consumptionRecord->started_at }} → {{ $consumptionRecord->ended_at }}</span></div>
        </div>
        <a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => 'finished-goods', 'workOrder' => $wo]) }}">Continue to FG store →</a>
    @else
        <p class="access-muted text-sm">This step opens after QC approves the accepted and defective quantities.</p>
    @endif
</section>

<section class="access-card p-5 production-panel"><h2 class="text-lg font-bold">Real Inventory BOM and consumption trace</h2>
    @if($bomItems->isNotEmpty())
        <div class="overflow-x-auto"><table class="access-table"><thead><tr><th>RM/PM component</th><th>Recipe per FG</th><th>Issued</th><th>Used</th><th>Scrap</th><th>Returned</th></tr></thead><tbody>
            @foreach($bomItems as $line)
                @php($usage = $consumptionLines->first(fn ($row) => (int)$row->inventory_item_id === (int)$line->component_item_id))
                <tr><td>{{ $line->component?->safeDisplayName() }}</td><td>{{ $line->qty_per_unit }} {{ $line->component?->unit }}</td><td>{{ number_format((float)$wo->materialIssues->where('inventory_item_id', $line->component_item_id)->where('movement_type', 'issue')->sum('qty'), 3) }}</td><td>{{ $usage?->used_qty ?? 'Pending' }}</td><td>{{ $usage?->scrap_qty ?? 'Pending' }}</td><td>{{ $usage?->return_qty ?? 'Pending' }}</td></tr>
            @endforeach
        </tbody></table></div>
    @else<p class="access-muted text-sm">No mapped Inventory recipe is linked to this work order.</p>@endif
</section>
