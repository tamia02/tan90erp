<?php

use App\Models\GrnRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $rows = GrnRecord::with(['gateEntry', 'lines'])
            ->orderByDesc('created_at')
            ->get();

        return ['records' => $rows];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Purchase Receives (GRN Register)</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every GRN posted so far.</p>
    </section>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Gate No</th>
                        <th class="px-4 py-2.5 font-medium">SKU / Material</th>
                        <th class="px-4 py-2.5 font-medium text-right">Unit Price</th>
                        <th class="px-4 py-2.5 font-medium text-right">Invoice Value</th>
                        <th class="px-4 py-2.5 font-medium text-right">Accepted Value</th>
                        <th class="px-4 py-2.5 font-medium">Accepted</th>
                        <th class="px-4 py-2.5 font-medium">Hold</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Posted</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $r)
                        @php($lines = $r->lines)
                        @php($multiSku = $lines->count() > 1)
                        @php($line = $lines->first())
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium">
                                @if ($r->gateEntry)
                                    <a href="{{ route('gate-entries.show', $r->gateEntry) }}" wire:navigate style="color: var(--brand);">{{ $r->gateEntry->gate_no }}</a>
                                @else
                                    <span style="color: var(--text-primary);">—</span>
                                @endif
                            </td>
                            @if ($multiSku)
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-secondary);">
                                <details>
                                    <summary class="cursor-pointer font-semibold" style="color:var(--brand);">{{ $lines->count() }} SKU lines · view per-SKU values</summary>
                                    <div class="mt-2 overflow-x-auto">
                                        <table class="min-w-[1050px] w-full text-xs"><thead><tr class="text-left" style="color:var(--text-muted);"><th class="p-2">SKU</th><th class="p-2 text-right">Unit Price</th><th class="p-2 text-right">Invoice Value</th><th class="p-2 text-right">Accepted Value</th><th class="p-2">Accepted</th><th class="p-2">Hold</th><th class="p-2">Defective</th><th class="p-2">Rejected</th><th class="p-2">Bin</th></tr></thead><tbody>
                                            @foreach ($lines as $skuLine)
                                                <tr style="border-top:1px solid var(--border);"><td class="p-2 font-medium">{{ $skuLine->sku }}</td><td class="p-2 text-right">{{ $skuLine->unit_price !== null ? number_format((float) $skuLine->unit_price, 4) : '—' }}</td><td class="p-2 text-right">{{ $skuLine->invoice_amount !== null ? number_format((float) $skuLine->invoice_amount, 2) : '—' }}</td><td class="p-2 text-right font-semibold">{{ $skuLine->accepted_value !== null ? number_format((float) $skuLine->accepted_value, 2) : '—' }}</td><td class="p-2">{{ $skuLine->accepted_qty }}</td><td class="p-2">{{ $skuLine->qc_hold_qty }}</td><td class="p-2">{{ $skuLine->defective_qty }}</td><td class="p-2">{{ $skuLine->rejected_qty }}</td><td class="p-2">{{ $skuLine->suggested_bin ?: $r->suggested_bin ?: '—' }}</td></tr>
                                            @endforeach
                                        </tbody></table>
                                    </div>
                                </details>
                            </td>
                            <td class="px-4 py-2.5 text-right">—</td><td class="px-4 py-2.5 text-right">—</td><td class="px-4 py-2.5 text-right">—</td>
                            <td class="px-4 py-2.5 text-xs" colspan="5" style="color:var(--text-muted);">Amounts and quantities remain separated by SKU above; no combined GRN total.</td>
                            @else
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-secondary);">{{ $line?->sku ?? $r->sku }}</td>
                            <td class="px-4 py-2.5 text-right">{{ $line?->unit_price !== null ? number_format((float) $line->unit_price, 4) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right">{{ $line?->invoice_amount !== null ? number_format((float) $line->invoice_amount, 2) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold">{{ $line?->accepted_value !== null ? number_format((float) $line->accepted_value, 2) : '—' }}</td>
                            <td class="px-4 py-2.5">{{ $line?->accepted_qty ?? $r->accepted_qty }}</td>
                            <td class="px-4 py-2.5" style="{{ ($line?->qc_hold_qty ?? $r->qc_hold_qty) > 0 ? 'color: var(--status-warning); font-weight: 600;' : '' }}">{{ $line?->qc_hold_qty ?? $r->qc_hold_qty }}</td>
                            <td class="px-4 py-2.5">{{ $line?->defective_qty ?? $r->defective_qty }}</td>
                            <td class="px-4 py-2.5">{{ $line?->rejected_qty ?? $r->rejected_qty }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $line?->suggested_bin ?? $r->suggested_bin }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: {{ $r->posted && $r->gateEntry?->putaway_completed_at ? 'var(--status-good)' : 'var(--status-warning)' }};">
                                @if (! $r->posted)
                                    GRN pending
                                @elseif ($r->gateEntry?->putaway_completed_at)
                                    Put-away complete · stock reflected
                                @else
                                    GRN posted · put-away pending
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No GRNs posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
