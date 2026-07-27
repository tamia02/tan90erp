<?php

use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return ['entries' => GateEntry::orderByDesc('created_at')->get()];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Guard Entries</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every gate entry logged so far.</p>

    <div class="flex flex-col gap-2">
        @forelse ($entries as $entry)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $entry->gate_no }} <span class="text-xs" style="color: var(--text-muted); font-weight: normal; margin-left: 4px;">&bull; Type: {{ ucfirst($entry->entry_type) }}</span></span>
                    <span class="text-xs font-medium capitalize" style="color: var(--text-muted);">{{ str_replace('_', ' ', $entry->status) }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    {{ $entry->vendor_name ?? $entry->vehicle_number }} · {{ $entry->material ?? 'No material set' }} · {{ $entry->invoice_qty ?? '—' }} qty
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                    <div class="text-xs" style="color: var(--text-muted);">
                        {{ $entry->vehicle_number }} · {{ $entry->driver_name }} · {{ $entry->created_at->format('d M Y, H:i') }}
                    </div>
                    <a href="{{ route('guard.entries.show', $entry) }}" wire:navigate class="text-xs font-medium shrink-0" style="color: var(--brand);">View Activity</a>
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No gate entries yet.</div>
        @endforelse
    </div>
</div>
