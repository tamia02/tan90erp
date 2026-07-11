<?php

use App\Models\GateEntry;
use App\Models\UnloadingRecord;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $allotting = null;
    public ?int $completing = null;
    public string $boxCount = '';
    public string $stagingArea = 'Staging Bay 1';
    public string $podLrRef = '';

    public array $stagingAreas = ['Staging Bay 1', 'Staging Bay 2', 'Staging Bay 3', 'Staging Bay 4'];

    public function openAllot(int $gateId): void
    {
        $this->allotting = $gateId;
        $this->stagingArea = 'Staging Bay 1';
    }

    public function allot(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        UnloadingRecord::create([
            'gate_entry_id' => $gate->id,
            'box_count' => 0,
            'staging_area' => $this->stagingArea,
            'unloaded_by' => auth()->user()->name,
            'allotted_at' => now(),
        ]);

        $gate->update(['status' => 'allotted']);

        AuditLogger::log('Allotted for unloading', "{$gate->gate_no} · {$this->stagingArea}", $gate);

        $this->reset(['allotting']);
        $this->stagingArea = 'Staging Bay 1';
    }

    public function startUnloading(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        $gate->unloadingRecord->update(['started_at' => now()]);
        $gate->update(['status' => 'unloading']);

        AuditLogger::log('Unloading started', $gate->gate_no, $gate);
    }

    public function completeUnloading(int $gateId): void
    {
        $this->validate(['boxCount' => ['required', 'integer', 'min:0']]);

        $gate = GateEntry::findOrFail($gateId);
        $gate->unloadingRecord->update([
            'box_count' => $this->boxCount,
            'staging_area' => $this->stagingArea,
            'pod_lr_ref' => $this->podLrRef ?: null,
            'completed_at' => now(),
        ]);

        // 'grn' here means "ready for QC Check" — GRN Check/posting is a
        // separate, later step owned by Store Manager, not this one.
        $gate->update(['status' => 'grn']);

        AuditLogger::log('Unloading completed, sent to QC Check', $gate->gate_no, $gate);

        $this->reset(['completing', 'boxCount', 'podLrRef']);
        $this->stagingArea = 'Staging Bay 1';
    }

    public function with(): array
    {
        return [
            'toAllot' => GateEntry::where('status', 'validated')->orderBy('created_at')->get(),
            'toStart' => GateEntry::with('unloadingRecord')->where('status', 'allotted')->orderBy('created_at')->get(),
            'inProgress' => GateEntry::with('unloadingRecord')->where('status', 'unloading')->orderBy('created_at')->get(),
            'history' => UnloadingRecord::with('gateEntry')->orderByDesc('created_at')->limit(10)->get(),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Unloading Desk</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Allot a staging bay, then start and complete unloading for cleared vehicles.</p>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">To allot</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toAllot as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                    </div>
                    @if ($allotting !== $g->id)
                        <button wire:click="openAllot({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">Allot</button>
                    @endif
                </div>
                @if ($allotting === $g->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Staging area</span>
                            <select wire:model="stagingArea" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                @foreach ($stagingAreas as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
                            </select>
                        </label>
                        <button wire:click="allot({{ $g->id }})" class="self-end rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Confirm allotment</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting to be allotted.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Ready to start</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toStart as $g)
            <div class="rounded-lg border p-4 flex items-center justify-between gap-3" style="background: var(--surface-3); border-color: var(--border);">
                <div>
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · Allotted to {{ $g->unloadingRecord?->staging_area }}</div>
                </div>
                <button wire:click="startUnloading({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">Start unloading</button>
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
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                    </div>
                    @if ($completing !== $g->id)
                        <button wire:click="$set('completing', {{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Complete</button>
                    @endif
                </div>
                @if ($completing === $g->id)
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Box count</span>
                            <input wire:model="boxCount" type="number" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Staging area</span>
                            <select wire:model="stagingArea" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                @foreach ($stagingAreas as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">POD / LR ref</span>
                            <input wire:model="podLrRef" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="LR-88213" />
                        </label>
                        <button wire:click="completeUnloading({{ $g->id }})" class="sm:col-span-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Complete &amp; send to QC Check</button>
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
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $r->completed_at ? 'Completed' : 'In progress' }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $r->gateEntry?->vendor_name }} · {{ $r->box_count }} boxes · {{ $r->staging_area }}</div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Allotted {{ $r->allotted_at?->format('d M, H:i') }}{{ $r->started_at ? ' · Started '.$r->started_at->format('d M, H:i') : '' }}{{ $r->completed_at ? ' · Completed '.$r->completed_at->format('d M, H:i') : '' }}</div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No unloading records yet.</div>
        @endforelse
    </div>
</div>
