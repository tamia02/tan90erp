<?php

use App\Models\LedgerEntry;
use App\Models\QcResult;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'results' => QcResult::with('gateEntry')->orderByDesc('created_at')->get(),
            'entries' => LedgerEntry::orderByDesc('created_at')->limit(20)->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">QC Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">History</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every QC Check recorded so far.</p>
    </section>

    <div class="flex flex-col gap-2 mb-6">
        @forelse ($results as $r)
            <a href="{{ $r->gateEntry ? route('gate-entries.show', $r->gateEntry) : '#' }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $r->created_at->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $r->sku }} · accepted {{ $r->accepted_qty }}, hold {{ $r->qc_hold_qty }}, defective {{ $r->defective_qty }}, rejected {{ $r->rejected_qty }}</div>
                @if ($r->qc_reasons)
                    <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $r->qc_reasons }}</div>
                @endif
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No QC checks recorded yet.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Stock Ledger</h2>
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Read-only — every posting from GRN Check.</p>
    @include('partials.stock-ledger-table')
</div>
