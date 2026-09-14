<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return ['entries' => LedgerEntry::orderByDesc('created_at')->get()];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Stock Ledger</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every posting made by GRN Check — immutable, append-only.</p>
    </section>

    @include('partials.stock-ledger-table')
</div>
