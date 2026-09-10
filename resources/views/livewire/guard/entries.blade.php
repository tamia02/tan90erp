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

    public function with(): array
    {
        $entries = GateEntry::query()
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
            ->orderByDesc('created_at')
            ->get();

        return ['entries' => $entries];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Guard Entries</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every gate entry logged so far.</p>

    <div class="flex flex-col sm:flex-row gap-2 mb-4">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search gate no, vendor, driver, vehicle, invoice, PO..." class="w-full rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);" />
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
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">{{ $search !== '' || $status !== '' ? 'No gate entries match your search.' : 'No gate entries yet.' }}</div>
        @endforelse
    </div>
</div>
