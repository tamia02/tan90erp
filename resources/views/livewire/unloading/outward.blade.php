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

    public array $documentsChecked = ['invoice' => false, 'bill' => false, 'pod' => false, 'lr' => false];

    public bool $documentsPackConfirmed = false;

    public function startLoading(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'dock_assigned' || $gate->entry_type !== 'outward' || $gate->loading_started_at) {
            return;
        }

        // Loading-window dates are planning information, not a hard gate.
        // Vehicles can arrive early/late and the physical loading task must
        // still be recordable. Capture the actual start time and show the
        // schedule as context only.
        $this->resetValidation('loadingWindow');

        $gate->update(['loading_started_at' => now()]);
        AuditLogger::log('Outward loading started', "{$gate->gate_no} · {$gate->loading_dock}", $gate);
        session()->flash('success', "{$gate->gate_no} loading started. Complete the document handover checklist when loading is finished. The next owner is Store Executive until loading is confirmed.");
    }

    public function openCompletion(int $gateId): void
    {
        $this->completing = $gateId;
        $this->documentsChecked = ['invoice' => false, 'bill' => false, 'pod' => false, 'lr' => false];
        $this->documentsPackConfirmed = false;
    }

    /** Backwards-compatible action name for older links/tests. */
    public function startCompleting(int $gateId): void
    {
        $this->openCompletion($gateId);
    }

    public function completeLoading(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        $rules = [
            'documentsChecked.invoice' => ['accepted'],
            'documentsChecked.bill' => ['accepted'],
            'documentsChecked.pod' => ['accepted'],
            'documentsChecked.lr' => ['accepted'],
            'documentsPackConfirmed' => ['accepted'],
        ];
        $messages = [
            'documentsChecked.invoice.accepted' => 'Confirm the Invoice was handed to the driver.',
            'documentsChecked.bill.accepted' => 'Confirm the Bill was handed to the driver.',
            'documentsChecked.pod.accepted' => 'Confirm the POD documents were handed to the driver.',
            'documentsChecked.lr.accepted' => 'Confirm the LR copy was handed to the driver.',
            'documentsPackConfirmed.accepted' => 'Confirm the complete dispatch document pack was handed to the driver.',
        ];
        $this->validate($rules, $messages);

        if ($gate->status !== 'dock_assigned' || $gate->entry_type !== 'outward' || ! $gate->loading_started_at) {
            $this->reset(['completing', 'documentsChecked', 'documentsPackConfirmed']);

            return;
        }

        $gate->update([
            'status' => 'loaded',
            'loaded_at' => now(),
            'dispatch_documents_shared' => true,
        ]);

        AuditLogger::log('Outward loading complete, document checklist handed to driver', $gate->gate_no, $gate);
        session()->flash('success', "{$gate->gate_no} loaded and documents shared. Guard must confirm the vehicle exit.");

        $this->reset(['completing', 'documentsChecked', 'documentsPackConfirmed']);
    }

    public function with(): array
    {
        return [
            'readyToLoad' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->orderByDesc('dock_assigned_at')->get(),
            'inwardActiveCount' => GateEntry::where('entry_type', 'inward')->whereIn('status', ['dock_assigned', 'allotted', 'unloading'])->count(),
            'history' => GateEntry::where('entry_type', 'outward')->whereNotNull('loaded_at')->orderByDesc('loaded_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive · Shared work area</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Loading &amp; Unloading</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Store Manager assigns the outward bay. Start and complete physical loading here; timestamps and driver document handover are captured automatically.</p>
        <nav aria-label="Loading and unloading workstreams" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
            <a href="{{ route('unloading.desk') }}" wire:navigate class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold transition hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                <span>Inward · Unloading</span><span class="rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">{{ $inwardActiveCount }} active</span>
            </a>
            <a href="{{ route('unloading.outward') }}" wire:navigate aria-current="page" class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="background: var(--brand-bg); border-color: var(--brand); color: var(--brand);">
                <span>Outward · Loading</span><span class="rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-1);">{{ $readyToLoad->count() }} active</span>
            </a>
        </nav>
    </section>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Approved dispatches · load or complete</h2>
    @error('loadingWindow') <div role="alert" class="rounded-lg border px-3 py-2 mb-3 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div> @enderror
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($readyToLoad as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · {{ $g->material }} · {{ $g->loading_dock }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">EDD {{ $g->expected_delivery_date?->format('d M Y') ?? '—' }} · Dispatch bay {{ $g->loading_dock }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Loading window: {{ $g->loading_window_start?->format('d M Y, H:i') ?? 'Not scheduled' }} – {{ $g->loading_window_end?->format('H:i') ?? '—' }}</div>
                    </a>
                    @if (! $g->loading_started_at)
                        <button wire:click="startLoading({{ $g->id }})" wire:loading.attr="disabled" wire:target="startLoading({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Start loading</button>
                    @elseif ($completing !== $g->id)
                        <button wire:click="openCompletion({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border shrink-0" style="border-color: var(--border); color: var(--text-primary);">Complete loading</button>
                    @endif
                </div>

                @if ($g->loading_started_at)
                    <div class="mt-3 rounded-lg px-3 py-2 text-xs" style="background: var(--status-warning-bg); color: var(--status-warning);">Loading started {{ $g->loading_started_at->format('d M Y, H:i') }}. Complete the checklist after the vehicle is loaded.</div>
                @endif

                @if ($completing === $g->id)
                    <div class="mt-4 flex flex-col gap-3">
                        <div class="rounded-lg border p-3" style="border-color: var(--border);">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">Driver document handover checklist</div>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Tick every document physically handed to the driver. Uploads are not required here.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                                @foreach (['invoice' => 'Invoice', 'bill' => 'Bill', 'pod' => 'POD documents', 'lr' => 'LR copy'] as $key => $label)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="documentsChecked.{{ $key }}" class="rounded" />
                                        <span style="color: var(--text-primary);">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <label class="flex items-start gap-2 text-sm mt-3 border-t pt-3" style="border-color: var(--border);">
                                <input type="checkbox" wire:model="documentsPackConfirmed" class="rounded mt-0.5" />
                                <span style="color: var(--text-primary);">I confirm the complete required document pack has been handed to the driver.</span>
                            </label>
                        </div>
                        @foreach (['invoice' => 'Invoice', 'bill' => 'Bill', 'pod' => 'POD documents', 'lr' => 'LR copy'] as $key => $label)
                            @error("documentsChecked.{$key}") <span class="text-xs" style="color: var(--status-critical);">{{ $label }} must be checked.</span> @enderror
                        @endforeach
                        @error('documentsPackConfirmed') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
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
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="text-sm font-medium hover:opacity-80" style="color: var(--text-primary);">{{ $g->gate_no }}</a>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $g->exited_at ? 'Exited '.$g->exited_at->format('d M, H:i') : 'Loaded, awaiting gate exit' }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name }} · {{ $g->loading_dock }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Start time: {{ $g->loading_started_at?->format('d M Y, H:i') ?? '—' }} · End time: {{ $g->loaded_at?->format('d M Y, H:i') ?? 'Pending' }}</div>
                @if (! $g->exited_at)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Next owner: Guard — confirm exit</span>
                        <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-primary);">Open gate entry →</a>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No outward loading records yet.</div>
        @endforelse
    </div>
</div>
