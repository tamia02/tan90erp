<?php

use App\Models\GrnRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public GrnRecord $grn;

    public function mount(GrnRecord $grn): void
    {
        abort_unless($grn->posted, 404);
        $this->grn = $grn->load(['gateEntry', 'lines', 'creator']);
    }

    public function with(): array
    {
        return ['record' => $this->grn];
    }
}; ?>

<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div>
            <a href="{{ route('grn.check') }}" wire:navigate class="text-sm font-semibold hover:underline" style="color: var(--brand);">← Back to GRN list</a>
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Goods Receipt Note</p>
            <h1 class="mt-1 text-2xl font-bold" style="color: var(--text-primary);">{{ $record->number() }}</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">{{ $record->gateEntry?->vendor_name ?: 'Vendor unavailable' }} · PO {{ $record->gateEntry?->po_number ?: '—' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('grn.export.pdf', ['gate' => $record->gateEntry, 'columns' => implode(',', array_keys(\App\Support\ReportColumns::GRN))]) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download PDF</a>
            <a href="{{ route('grn.export.excel', ['gate' => $record->gateEntry, 'columns' => implode(',', array_keys(\App\Support\ReportColumns::GRN))]) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download Excel</a>
        </div>
    </div>

    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Receipt summary</h2>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs" style="color: var(--text-muted);">Gate entry</dt><dd class="mt-1 font-medium">{{ $record->gateEntry?->gate_no ?: '—' }}</dd></div>
            <div><dt class="text-xs" style="color: var(--text-muted);">Invoice</dt><dd class="mt-1 font-medium">{{ $record->gateEntry?->invoice_number ?: '—' }}</dd></div>
            <div><dt class="text-xs" style="color: var(--text-muted);">Posted</dt><dd class="mt-1 font-medium">{{ $record->updated_at?->format('d M Y, H:i') ?: '—' }}</dd></div>
            <div><dt class="text-xs" style="color: var(--text-muted);">Posted by</dt><dd class="mt-1 font-medium">{{ $record->creator?->name ?: '—' }}</dd></div>
        </dl>
        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5">
            @foreach ([['Accepted', $record->accepted_qty, 'var(--status-good)'], ['QC hold', $record->qc_hold_qty, 'var(--status-warning)'], ['Missing', $record->missing_qty, 'var(--text-primary)'], ['Defective', $record->defective_qty, 'var(--status-critical)'], ['Rejected', $record->rejected_qty, 'var(--status-critical)']] as [$label, $qty, $color])
                <div class="rounded-xl border p-3" style="border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">{{ $label }}</div><div class="mt-1 text-lg font-bold" style="color: {{ $color }};">{{ rtrim(rtrim(number_format((float) $qty, 3, '.', ','), '0'), '.') ?: '0' }}</div></div>
            @endforeach
        </div>
    </section>

    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Received items</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead><tr class="border-b text-left text-xs uppercase" style="border-color: var(--border); color: var(--text-muted);"><th class="px-3 py-3">SKU / Item</th><th class="px-3 py-3 text-right">PO qty</th><th class="px-3 py-3 text-right">Physical received</th><th class="px-3 py-3 text-right">Accepted</th><th class="px-3 py-3 text-right">Hold</th><th class="px-3 py-3 text-right">Missing</th><th class="px-3 py-3 text-right">Defective</th><th class="px-3 py-3 text-right">Rejected</th><th class="px-3 py-3">Bin</th><th class="px-3 py-3 text-right">Accepted value</th></tr></thead>
                <tbody>
                    @forelse ($record->lines as $line)
                        <tr wire:key="grn-report-line-{{ $line->id }}" class="border-b last:border-0" style="border-color: var(--border);">
                            <td class="px-3 py-3 font-medium">{{ $line->sku }}</td><td class="px-3 py-3 text-right">{{ $line->po_qty }}</td><td class="px-3 py-3 text-right">{{ $line->physical_received }}</td><td class="px-3 py-3 text-right font-semibold" style="color: var(--status-good);">{{ $line->accepted_qty }}</td><td class="px-3 py-3 text-right">{{ $line->qc_hold_qty }}</td><td class="px-3 py-3 text-right">{{ $line->missing_qty }}</td><td class="px-3 py-3 text-right">{{ $line->defective_qty }}</td><td class="px-3 py-3 text-right">{{ $line->rejected_qty }}</td><td class="px-3 py-3">{{ $line->suggested_bin ?: '—' }}</td><td class="px-3 py-3 text-right">₹{{ number_format((float) $line->accepted_value, 2, '.', ',') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-3 py-8 text-center" style="color: var(--text-muted);">No SKU lines are recorded on this GRN.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
