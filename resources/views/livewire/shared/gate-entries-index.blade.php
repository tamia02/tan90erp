<?php

use App\Enums\Role;
use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

// Client explicitly reversed an earlier requirement here: the original build
// deliberately kept every role's sidebar to its own module only ("nothing
// shared across roles per the client" - see RoleNavigation's own comment).
// Client then reported the opposite: a guard entry needs to be visible to
// Store Executive and Store Manager, not just whichever narrow status-filtered
// queue their own screens happen to show. This is that shared, full list -
// every internal-staff role can browse every gate entry regardless of its
// current stage, not just the slice currently queued for their own action.
new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    private function searchQuery()
    {
        $user = auth()->user();

        return GateEntry::query()
            // Same ownership boundary as shared.gate-entry-detail and
            // shared.activity-detail: a vendor only ever sees their own.
            ->when($user->role === Role::Vendor, fn ($query) => $query->where('vendor_name', $user->name))
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
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status));
    }

    public function with(): array
    {
        return [
            'entries' => $this->searchQuery()->orderByDesc('created_at')->limit(200)->get(),
            'suggestions' => $this->search !== '' ? $this->searchQuery()->orderByDesc('created_at')->limit(6)->get() : collect(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">All Gate Entries</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every gate entry logged, at any stage — not just what's currently queued for your own action.</p>

    <div class="flex flex-col sm:flex-row gap-2 mb-4">
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
                        <a href="{{ route('gate-entries.show', $suggestion) }}" wire:navigate class="block px-3 py-2 text-sm hover:opacity-80" style="border-top: 1px solid var(--border);">
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

    <div class="flex flex-col gap-2">
        @forelse ($entries as $entry)
            <a href="{{ route('gate-entries.show', $entry) }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $entry->gate_no }} <span class="text-xs" style="color: var(--text-muted); font-weight: normal; margin-left: 4px;">&bull; Type: {{ ucfirst($entry->entry_type) }}</span></span>
                    <span class="text-xs font-medium capitalize" style="color: var(--text-muted);">{{ str_replace('_', ' ', $entry->status) }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    {{ $entry->vendor_name ?? $entry->vehicle_number }} · {{ $entry->material ?? 'No material set' }} · {{ $entry->invoice_qty ?? '—' }} qty
                </div>
                <div class="text-xs mt-2" style="color: var(--text-muted);">
                    {{ $entry->vehicle_number }} · {{ $entry->driver_name }} · {{ $entry->created_at->format('d M Y, H:i') }}
                </div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">{{ $search !== '' || $status !== '' ? 'No gate entries match your search.' : 'No gate entries yet.' }}</div>
        @endforelse
    </div>
</div>
