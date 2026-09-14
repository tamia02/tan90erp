<?php

use App\Models\QcResult;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'holds' => QcResult::with('gateEntry')
                ->where(fn ($q) => $q->where('qc_hold_qty', '>', 0)->orWhere('defective_qty', '>', 0)->orWhere('rejected_qty', '>', 0))
                ->orderByDesc('created_at')
                ->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">QC Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Quality Holds</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every delivery with a hold, defective, or rejected quantity.</p>
    </section>

    <div class="flex flex-col gap-2">
        @forelse ($holds as $r)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <a href="{{ $r->gateEntry ? route('gate-entries.show', $r->gateEntry) : '#' }}" wire:navigate class="block hover:opacity-80">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }} · {{ $r->sku }}</div>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $r->created_at->format('d M, H:i') }}</span>
                    </div>
                    <div class="text-xs mt-1 flex gap-3" style="color: var(--text-secondary);">
                        @if ($r->qc_hold_qty > 0) <span style="color: var(--status-warning);">Hold: {{ $r->qc_hold_qty }}</span> @endif
                        @if ($r->defective_qty > 0) <span style="color: var(--status-critical);">Defective: {{ $r->defective_qty }}</span> @endif
                        @if ($r->rejected_qty > 0) <span style="color: var(--status-critical);">Rejected: {{ $r->rejected_qty }}</span> @endif
                    </div>
                    @if ($r->qc_reasons)
                        <div class="text-xs mt-2" style="color: var(--text-muted);">{{ $r->qc_reasons }}</div>
                    @endif
                    @if ($r->hold_reason)
                        <div class="text-xs mt-2" style="color: var(--text-primary);"><span class="font-semibold" style="color: var(--text-muted);">Hold reason: </span>{{ $r->hold_reason }}</div>
                    @endif
                </a>
                @if ($r->hold_document_path)
                    <a href="{{ route('qc.hold-document', $r) }}" class="text-xs font-medium mt-1 inline-block" style="color: var(--brand);">View attached document →</a>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing on hold — every QC check has been clean.</div>
        @endforelse
    </div>
</div>
