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

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Stock Ledger</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every posting made by GRN Check — immutable, append-only.</p>

    @include('partials.stock-ledger-table')
</div>
