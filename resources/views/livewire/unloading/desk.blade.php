<?php

use App\Models\GateEntry;
use App\Models\UnloadingRecord;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $starting = null;
    public string $manpowerCount = '';
    public ?int $completing = null;
    public string $boxCount = '';
    public string $podLrRef = '';

    public array $stagingAreas = ['Staging Bay 1', 'Staging Bay 2', 'Staging Bay 3', 'Staging Bay 4'];

    // Staging bay is no longer picked by hand — the least-occupied bay is
    // located automatically the moment a vehicle is allotted.
    private function autoLocateStagingArea(): string
    {
        return collect($this->stagingAreas)
            ->sortBy(fn ($area) => UnloadingRecord::whereNull('completed_at')->where('staging_area', $area)->count())
            ->first();
    }

    public function allot(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        // Guards against a double-click (or stale re-render) re-submitting
        // an already-allotted gate — without this, the second submit hits
        // unloading_records' unique gate_entry_id constraint and 500s.
        if ($gate->status !== 'dock_assigned' || $gate->unloadingRecord) {
            return;
        }

        $stagingArea = $this->autoLocateStagingArea();

        UnloadingRecord::create([
            'gate_entry_id' => $gate->id,
            'box_count' => 0,
            'staging_area' => $stagingArea,
            'unloaded_by' => auth()->user()->name,
            'allotted_at' => now(),
        ]);

        $gate->update(['status' => 'allotted']);

        AuditLogger::log('Allotted for unloading', "{$gate->gate_no} · auto-located to {$stagingArea}", $gate);
    }

    // Confirmed missing against spec: "Store Manager aligns manpower and
    // unloading is done" had no manpower concept anywhere -- Start unloading
    // now requires a headcount first, matching that step.
    public function startUnloading(int $gateId): void
    {
        $this->validate(['manpowerCount' => ['required', 'integer', 'min:1']]);

        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'allotted' || ! $gate->unloadingRecord) {
            $this->reset(['starting', 'manpowerCount']);

            return;
        }

        $gate->unloadingRecord->update(['started_at' => now(), 'manpower_count' => $this->manpowerCount]);
        $gate->update(['status' => 'unloading']);

        AuditLogger::log('Unloading started', "{$gate->gate_no} · {$this->manpowerCount} crew assigned", $gate);

        $this->reset(['starting', 'manpowerCount']);
    }

    public function completeUnloading(int $gateId): void
    {
        // min:0 previously let a completed unloading record "0 boxes" --
        // confirmed live, accepted outright.
        $this->validate(['boxCount' => ['required', 'integer', 'min:1']]);

        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'unloading' || ! $gate->unloadingRecord) {
            $this->reset(['completing', 'boxCount', 'podLrRef']);

            return;
        }

        $gate->unloadingRecord->update([
            'box_count' => $this->boxCount,
            'pod_lr_ref' => $this->podLrRef ?: null,
            'completed_at' => now(),
        ]);

        // 'grn' here means "ready for QC Check" — GRN Check/posting is a
        // separate, later step owned by Store Manager, not this one.
        $gate->update(['status' => 'grn']);

        AuditLogger::log('Unloading completed, sent to QC Check', $gate->gate_no, $gate);

        $this->reset(['completing', 'boxCount', 'podLrRef']);
    }

    public function with(): array
    {
        return [
            // Same entry_type filter as Loading Desk, for the same reason:
            // visitor/outward entries never have unloading to do.
            'toAllot' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'inward')->orderBy('dock_assigned_at')->get(),
            'toStart' => GateEntry::with('unloadingRecord')->where('status', 'allotted')->where('entry_type', 'inward')->orderBy('created_at')->get(),
            'inProgress' => GateEntry::with('unloadingRecord')->where('status', 'unloading')->where('entry_type', 'inward')->orderBy('created_at')->get(),
            'history' => UnloadingRecord::with('gateEntry')->orderByDesc('created_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Unloading Desk</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Allot vehicles already on a loading dock, then start and complete unloading. Staging bay is auto-located.</p>
    </section>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">To allot</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toAllot as $g)
            <div class="rounded-lg border p-4 flex items-center justify-between gap-3" style="background: var(--surface-3); border-color: var(--border);">
                <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · On {{ $g->loading_dock }}</div>
                </a>
                <button wire:click="allot({{ $g->id }})" wire:loading.attr="disabled" wire:target="allot({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Allot &amp; auto-locate bay</button>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to be allotted.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Ready to start</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toStart as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · Allotted to {{ $g->unloadingRecord?->staging_area }}</div>
                    </a>
                    @if ($starting !== $g->id)
                        <button wire:click="$set('starting', {{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Start unloading</button>
                    @endif
                </div>
                @if ($starting === $g->id)
                    <div class="flex flex-col sm:flex-row gap-2 items-start mt-3">
                        <label class="flex flex-col gap-1.5 text-sm flex-1">
                            <span class="font-medium" style="color: var(--text-primary);">Manpower assigned</span>
                            <input wire:model="manpowerCount" type="number" min="1" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="No. of workers" />
                            @error('manpowerCount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="flex gap-2 sm:mt-6">
                            <button wire:click="startUnloading({{ $g->id }})" wire:loading.attr="disabled" wire:target="startUnloading({{ $g->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm &amp; start</button>
                            <button wire:click="$set('starting', null)" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to start.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">In progress</h2>
    <div class="flex flex-col gap-2">
        @forelse ($inProgress as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · {{ $g->unloadingRecord?->manpower_count }} crew</div>
                    </a>
                    @if ($completing !== $g->id)
                        <button wire:click="$set('completing', {{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Complete</button>
                    @endif
                </div>
                @if ($completing === $g->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Box count</span>
                            <input wire:model="boxCount" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('boxCount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">POD / LR ref</span>
                            <input wire:model="podLrRef" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="LR-88213" />
                        </label>
                        <button wire:click="completeUnloading({{ $g->id }})" wire:loading.attr="disabled" wire:target="completeUnloading({{ $g->id }})" class="sm:col-span-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Complete &amp; send to QC Check</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing in progress.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2 mt-6" style="color: var(--text-primary);">History</h2>
    <div class="flex flex-col gap-2">
        @forelse ($history as $r)
            <a href="{{ $r->gateEntry ? route('gate-entries.show', $r->gateEntry) : '#' }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</div>
                    {{-- Confirmed live: this only ever checked completed_at, so an
                         entry that had only been allotted (started_at still null)
                         showed as "In progress" with 0 boxes -- box_count and
                         started_at both come from startUnloading(), not allot(). --}}
                    <span class="text-xs" style="color: var(--text-muted);">{{ $r->completed_at ? 'Completed' : ($r->started_at ? 'In progress' : 'Allotted, not started') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $r->gateEntry?->vendor_name }} · {{ $r->started_at ? $r->box_count.' boxes' : 'Not unloaded yet' }} · {{ $r->staging_area }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Allotted {{ $r->allotted_at?->format('d M, H:i') }}{{ $r->started_at ? ' · Started '.$r->started_at->format('d M, H:i') : '' }}{{ $r->completed_at ? ' · Completed '.$r->completed_at->format('d M, H:i') : '' }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No unloading records yet.</div>
        @endforelse
    </div>
</div>
