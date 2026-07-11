<?php

use App\Models\GateEntry;
use App\Models\UnloadingRecord;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $tab = 'loading';

    public string $search = '';

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->search = '';
    }

    private function loadingHistory()
    {
        $query = GateEntry::whereNotNull('dock_assigned_at')->orderByDesc('dock_assigned_at');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('gate_no', 'like', "%{$this->search}%")
                    ->orWhere('vendor_name', 'like', "%{$this->search}%")
                    ->orWhere('loading_dock', 'like', "%{$this->search}%");
            });

            return $query->get();
        }

        return $query->limit(10)->get();
    }

    private function unloadingHistory()
    {
        $query = UnloadingRecord::with('gateEntry')->orderByDesc('created_at');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('staging_area', 'like', "%{$this->search}%")
                    ->orWhereHas('gateEntry', function ($gq) {
                        $gq->where('gate_no', 'like', "%{$this->search}%")
                            ->orWhere('vendor_name', 'like', "%{$this->search}%");
                    });
            });

            return $query->get();
        }

        return $query->limit(10)->get();
    }

    public function with(): array
    {
        return [
            'loadingHistory' => $this->tab === 'loading' ? $this->loadingHistory() : collect(),
            'unloadingHistory' => $this->tab === 'unloading' ? $this->unloadingHistory() : collect(),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">History</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Last 10 entries by default — search to see everything.</p>

    <div class="flex gap-2 mb-4">
        <button wire:click="setTab('loading')" class="rounded-lg px-3.5 py-2 text-sm font-medium" style="background: {{ $tab === 'loading' ? 'var(--brand)' : 'var(--surface-3)' }}; color: {{ $tab === 'loading' ? '#fff' : 'var(--text-primary)' }}; border: 1px solid var(--border);">Loading</button>
        <button wire:click="setTab('unloading')" class="rounded-lg px-3.5 py-2 text-sm font-medium" style="background: {{ $tab === 'unloading' ? 'var(--brand)' : 'var(--surface-3)' }}; color: {{ $tab === 'unloading' ? '#fff' : 'var(--text-primary)' }}; border: 1px solid var(--border);">Unloading</button>
    </div>

    <input
        wire:model.live.debounce.400ms="search"
        type="text"
        placeholder="Search by gate no, vendor, {{ $tab === 'loading' ? 'dock' : 'staging area' }}…"
        class="w-full rounded-lg border px-3 py-2 text-sm mb-4"
        style="border-color: var(--border);"
    />

    @if ($tab === 'loading')
        <div class="flex flex-col gap-2">
            @forelse ($loadingHistory as $g)
                <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }} · {{ $g->loading_dock }}</div>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $g->dock_assigned_at?->format('d M, H:i') }}</span>
                    </div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                </div>
            @empty
                <div class="text-center text-sm py-10" style="color: var(--text-muted);">{{ $search !== '' ? 'No matching loading history.' : 'No loading history yet.' }}</div>
            @endforelse
        </div>
    @else
        <div class="flex flex-col gap-2">
            @forelse ($unloadingHistory as $r)
                <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</div>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $r->completed_at ? 'Completed' : 'In progress' }}</span>
                    </div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $r->gateEntry?->vendor_name }} · {{ $r->box_count }} boxes · {{ $r->staging_area }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Allotted {{ $r->allotted_at?->format('d M, H:i') }}{{ $r->started_at ? ' · Started '.$r->started_at->format('d M, H:i') : '' }}{{ $r->completed_at ? ' · Completed '.$r->completed_at->format('d M, H:i') : '' }}</div>
                </div>
            @empty
                <div class="text-center text-sm py-10" style="color: var(--text-muted);">{{ $search !== '' ? 'No matching unloading history.' : 'No unloading history yet.' }}</div>
            @endforelse
        </div>
    @endif
</div>
