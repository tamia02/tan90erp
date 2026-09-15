<?php

use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $breached = false;

    #[Url]
    public bool $today = false;

    private function searchQuery()
    {
        return GateEntry::query()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($query) use ($term) {
                    $query->where('gate_no', 'like', $term)
                        ->orWhere('vendor_name', 'like', $term)
                        ->orWhere('driver_name', 'like', $term)
                        ->orWhere('vehicle_number', 'like', $term)
                        ->orWhere('invoice_number', 'like', $term)
                        ->orWhere('po_number', 'like', $term);
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->breached, fn ($query) => $query->where('status', '!=', 'closed')->where('sla_deadline', '<', now()))
            ->when($this->today, fn ($query) => $query->whereDate('created_at', today()));
    }

    public function with(): array
    {
        return [
            'entries' => $this->searchQuery()->orderByDesc('created_at')->get(),
            // Live suggestion dropdown as you type — jump straight to a
            // match instead of scanning the full filtered list below.
            'suggestions' => $this->search !== '' ? $this->searchQuery()->orderByDesc('created_at')->limit(6)->get() : collect(),
        ];
    }

}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Guard Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Guard Entries</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every gate entry logged so far.</p>
    </section>

    <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative w-full" x-data="{ open: false }" @click.outside="open = false">
            <input
                wire:model.live.debounce.300ms="search"
                x-on:focus="open = true"
                x-on:input="open = true"
                type="search"
                placeholder="Search gate no, vendor, driver, vehicle, invoice, PO..."
                class="w-full rounded-xl border px-3 py-2.5 text-sm"
                style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);"
                autocomplete="off"
            />
            @if ($search !== '' && $suggestions->isNotEmpty())
                <div x-show="open" x-cloak class="absolute z-10 mt-1 w-full rounded-xl border shadow-lg overflow-hidden" style="border-color: var(--border); background: var(--surface-1);">
                    @foreach ($suggestions as $suggestion)
                        <a href="{{ route('guard.entries.show', $suggestion) }}" wire:navigate class="block px-3 py-2 text-sm hover:opacity-80" style="border-top: 1px solid var(--border);">
                            <span class="font-medium" style="color: var(--text-primary);">{{ $suggestion->gate_no }}</span>
                            <span style="color: var(--text-muted);"> — {{ $suggestion->vendor_name ?? $suggestion->vehicle_number }} · {{ $suggestion->driver_name }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
        <select wire:model.live="status" class="rounded-xl border px-3 py-2.5 text-sm sm:w-56" style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);">
            <option value="">All statuses</option>
            <option value="pending_validation">Pending Validation</option>
            <option value="validated">Validated</option>
            <option value="allotted">Allotted</option>
            <option value="dock_assigned">Dock Assigned</option>
            <option value="unloading">Unloading</option>
            <option value="grn">Ready for QC</option>
            <option value="qc_done">QC Done</option>
            <option value="rejected">Rejected</option>
            <option value="closed">Closed</option>
        </select>
    </div>

    @if ($today || $breached)
        <div class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
            <span class="px-2 py-1 rounded-full font-medium" style="background: var(--brand-bg); color: var(--brand);">
                {{ $today ? "Today's entries only" : '' }}{{ $today && $breached ? ' · ' : '' }}{{ $breached ? 'SLA breached only' : '' }}
            </span>
            <a href="{{ route('guard.entries') }}" wire:navigate class="font-medium" style="color: var(--brand);">Clear</a>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @forelse ($entries as $entry)
            @php
                $statusColor = match ($entry->status) {
                    'closed' => 'good',
                    'pending_validation', 'rejected' => 'critical',
                    default => 'warning',
                };
            @endphp
            <a href="{{ route('guard.entries.show', $entry) }}" wire:navigate class="block rounded-2xl border p-4 transition-colors hover:border-[var(--brand)]" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $entry->gate_no }}</span>
                    <span class="text-xs font-semibold capitalize px-2 py-0.5 rounded-full" style="background: var(--status-{{ $statusColor }}-bg); color: var(--status-{{ $statusColor }});">{{ str_replace('_', ' ', $entry->status) }}</span>
                </div>
                <div class="text-xs mt-1.5" style="color: var(--text-muted);">{{ ucfirst($entry->entry_type) }} entry</div>
                <div class="text-xs mt-2" style="color: var(--text-secondary);">
                    {{ $entry->vendor_name ?? $entry->vehicle_number }} · {{ $entry->material ?? 'No material set' }} · {{ $entry->invoice_qty ?? '—' }} qty
                </div>
                <div class="text-xs mt-2" style="color: var(--text-muted);">
                    {{ $entry->vehicle_number }} · {{ $entry->driver_name }} · {{ $entry->created_at->format('d M Y, H:i') }}
                </div>
            </a>
        @empty
            <div class="lg:col-span-2 text-center text-sm py-10" style="color: var(--text-muted);">{{ $search !== '' || $status !== '' ? 'No gate entries match your search.' : 'No gate entries yet.' }}</div>
        @endforelse
    </div>
</div>
