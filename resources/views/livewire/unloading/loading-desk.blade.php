<?php

use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Compatibility view for saved URLs: inward dock assignment now belongs to
// Store Manager on Entry Approvals; Store Executive sees only the handoff.
new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'toAssign' => GateEntry::where('status', 'pending_validation')->where('entry_type', 'inward')->orderByDesc('created_at')->get(),
            'assigned' => GateEntry::whereIn('status', ['dock_assigned', 'allotted', 'unloading'])->where('entry_type', 'inward')->orderByDesc('dock_assigned_at')->get(),
            'history' => GateEntry::whereNotNull('dock_assigned_at')->where('entry_type', 'inward')->orderByDesc('dock_assigned_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Inward Handoff Status</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Store Manager assigns the operational dock. Store Executive uses the Unloading Desk for physical count, POD capture and unloading.</p>
    </section>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Awaiting Store Manager approval and dock assignment</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($toAssign as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                    </a>
                    <div class="flex flex-wrap justify-end gap-2">
                        <span class="rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">Pending approval · no gate entry yet</span>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing waiting for a dock.</p>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Dock assigned — ready for physical unloading</h2>
    <div class="flex flex-col gap-2">
        @forelse ($assigned as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="text-sm font-medium hover:opacity-80" style="color: var(--text-primary);">{{ $g->gate_no }} · {{ $g->loading_dock }}</a>
                    <span class="text-xs" style="color: var(--text-muted);">Since {{ $g->dock_assigned_at?->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }} · {{ ucfirst($g->status) }}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('unloading.desk') }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold text-white" style="background: var(--brand);">Open Unloading Desk →</a>
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-primary);">View entry details</a>
                </div>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">No vehicles on a dock right now.</p>
        @endforelse
    </div>
</div>
