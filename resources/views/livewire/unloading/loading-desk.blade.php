<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $assigning = null;
    public string $dock = '';

    public array $docks = ['Dock 1', 'Dock 2', 'Dock 3', 'Dock 4'];

    private function occupiedDocks(): array
    {
        return GateEntry::where('status', 'dock_assigned')->pluck('loading_dock')->filter()->all();
    }

    private function availableDocks(): array
    {
        return array_values(array_diff($this->docks, $this->occupiedDocks()));
    }

    public function openAssign(int $gateId): void
    {
        $this->assigning = $gateId;
        $this->dock = $this->availableDocks()[0] ?? '';
    }

    public function assignDock(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        // Guards against a double-click re-submitting an already-assigned
        // gate before the UI has re-rendered.
        if ($gate->status !== 'validated') {
            $this->reset(['assigning', 'dock']);

            return;
        }

        $this->validate(['dock' => ['required', 'string', Rule::in($this->availableDocks())]]);

        $gate->update([
            'status' => 'dock_assigned',
            'loading_dock' => $this->dock,
            'dock_assigned_at' => now(),
        ]);

        AuditLogger::log('Assigned to loading dock', "{$gate->gate_no} · {$this->dock}", $gate);

        $this->reset(['assigning', 'dock']);
    }

    public function with(): array
    {
        $occupied = $this->occupiedDocks();

        return [
            'toAssign' => GateEntry::where('status', 'validated')->orderBy('created_at')->get(),
            'assigned' => GateEntry::where('status', 'dock_assigned')->orderBy('dock_assigned_at')->get(),
            'availableDocks' => $this->availableDocks(),
            'totalDocks' => count($this->docks),
            'occupiedCount' => count($occupied),
            'history' => GateEntry::whereNotNull('dock_assigned_at')->orderByDesc('dock_assigned_at')->limit(10)->get(),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Loading Desk</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Assign a loading dock to cleared vehicles before they move to the Unloading Desk.</p>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Loading Docks</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $occupiedCount }} / {{ $totalDocks }} occupied</div>
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">To assign</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toAssign as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                    </div>
                    @if ($assigning !== $g->id)
                        <button wire:click="openAssign({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);" @if (empty($availableDocks)) disabled @endif>Assign dock</button>
                    @endif
                </div>
                @if ($assigning === $g->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Loading dock</span>
                            <select wire:model="dock" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                @foreach ($availableDocks as $d) <option value="{{ $d }}">{{ $d }}</option> @endforeach
                            </select>
                            @error('dock') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <button wire:click="assignDock({{ $g->id }})" wire:loading.attr="disabled" wire:target="assignDock({{ $g->id }})" class="self-end rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm dock assignment</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting for a dock.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Currently on a dock</h2>
    <div class="flex flex-col gap-2">
        @forelse ($assigned as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }} · {{ $g->loading_dock }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">Since {{ $g->dock_assigned_at?->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · waiting on Unloading Desk to allot a staging area</div>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">No vehicles on a dock right now.</p>
        @endforelse
    </div>
</div>
