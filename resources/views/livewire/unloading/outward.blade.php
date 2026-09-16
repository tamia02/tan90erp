<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Outward's equivalent of the inward Unloading Desk: once Store Manager has
// approved the dispatch and assigned a bay, Store Exec physically loads the
// vehicle, does the post-load QC check, and hands the driver the required
// documents before the vehicle is allowed to leave (Guard confirms the
// actual exit on the gate entry's own detail page).
new #[Layout('layouts.app')] class extends Component
{
    public ?int $completing = null;
    public bool $qcPassed = true;
    public bool $documentsShared = false;
    public string $loadingRemarks = '';

    public function startCompleting(int $gateId): void
    {
        $this->completing = $gateId;
        $this->qcPassed = true;
        $this->documentsShared = false;
        $this->loadingRemarks = '';
    }

    public function completeLoading(int $gateId): void
    {
        $this->validate([
            'documentsShared' => ['accepted'],
        ], [
            'documentsShared.accepted' => 'Confirm the required documents (invoice, e-way bill, etc.) were handed to the driver before completing.',
        ]);

        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'dock_assigned' || $gate->entry_type !== 'outward') {
            $this->reset(['completing', 'qcPassed', 'documentsShared', 'loadingRemarks']);

            return;
        }

        $gate->update([
            'status' => 'loaded',
            'loaded_at' => now(),
            'dispatch_documents_shared' => true,
            'remarks' => trim($gate->remarks."\nLoading: ".($this->qcPassed ? 'post-load QC passed' : 'post-load QC issue noted').($this->loadingRemarks ? ' — '.$this->loadingRemarks : '')),
        ]);

        AuditLogger::log('Outward loading complete, documents shared with driver', $gate->gate_no.($this->qcPassed ? '' : ' · QC issue noted'), $gate);

        $this->reset(['completing', 'qcPassed', 'documentsShared', 'loadingRemarks']);
    }

    public function with(): array
    {
        return [
            'readyToLoad' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->orderBy('dock_assigned_at')->get(),
            'history' => GateEntry::where('entry_type', 'outward')->whereNotNull('loaded_at')->orderByDesc('loaded_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Outward Loading</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Dispatches approved and assigned a bay by Store Manager — load, run post-load QC, and hand documents to the driver.</p>
    </section>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Ready to load</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($readyToLoad as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · {{ $g->material }} · {{ $g->loading_dock }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">EDD {{ $g->expected_delivery_date?->format('d M Y') ?? '—' }} · Window {{ $g->loading_window_start?->format('d M, H:i') }}–{{ $g->loading_window_end?->format('H:i') }}</div>
                    </a>
                    @if ($completing !== $g->id)
                        <button wire:click="startCompleting({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border shrink-0" style="border-color: var(--border); color: var(--text-primary);">Complete loading</button>
                    @endif
                </div>

                @if ($completing === $g->id)
                    <div class="mt-4 flex flex-col gap-3">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="qcPassed" class="rounded" />
                            <span style="color: var(--text-primary);">Post-load QC passed</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="documentsShared" class="rounded" />
                            <span style="color: var(--text-primary);">Required documents handed to driver</span>
                        </label>
                        @error('documentsShared') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        <textarea wire:model="loadingRemarks" rows="2" placeholder="Remarks (optional)" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                        <div class="flex gap-2">
                            <button wire:click="completeLoading({{ $g->id }})" wire:loading.attr="disabled" wire:target="completeLoading({{ $g->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm &amp; ready for exit</button>
                            <button wire:click="$set('completing', null)" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to load.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">History</h2>
    <div class="flex flex-col gap-2">
        @forelse ($history as $g)
            <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $g->exited_at ? 'Exited '.$g->exited_at->format('d M, H:i') : 'Loaded, awaiting gate exit' }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name }} · {{ $g->loading_dock }} · Loaded {{ $g->loaded_at?->format('d M, H:i') }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No outward loading records yet.</div>
        @endforelse
    </div>
</div>
