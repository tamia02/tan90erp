<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// The visitor journey's approval step, routed to whichever specific person
// the visitor asked to see (visitor_host_id) rather than any one role --
// previously "Person To Meet" was free text nobody ever acted on, so a
// visitor was let in the instant Guard saved the form. Shared across every
// role since the host could be anyone (Store Manager, QC, Finance, ...).
new #[Layout('layouts.app')] class extends Component
{
    public ?int $denying = null;
    public string $denyReason = '';

    public function approve(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->entry_type !== 'visitor' || $gate->status !== 'pending_validation' || $gate->visitor_host_id !== auth()->id()) {
            return;
        }

        $gate->update([
            'status' => 'validated',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        AuditLogger::log('Visitor approved', "{$gate->gate_no} · {$gate->driver_name} approved by ".auth()->user()->name, $gate);
    }

    public function startDeny(int $gateId): void
    {
        $this->denying = $gateId;
        $this->denyReason = '';
    }

    public function cancelDeny(): void
    {
        $this->reset(['denying', 'denyReason']);
    }

    public function deny(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->entry_type !== 'visitor' || $gate->status !== 'pending_validation' || $gate->visitor_host_id !== auth()->id()) {
            $this->cancelDeny();

            return;
        }

        $gate->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'remarks' => trim($gate->remarks."\nEntry denied by ".auth()->user()->name.($this->denyReason ? ': '.$this->denyReason : '')),
        ]);

        AuditLogger::log('Visitor denied', "{$gate->gate_no} · {$gate->driver_name} denied by ".auth()->user()->name, $gate);

        $this->cancelDeny();
    }

    public function with(): array
    {
        return [
            'pendingForMe' => GateEntry::where('entry_type', 'visitor')
                ->where('status', 'pending_validation')
                ->where('visitor_host_id', auth()->id())
                ->orderByDesc('created_at')
                ->get(),
            'decidedByMe' => GateEntry::where('entry_type', 'visitor')
                ->where('visitor_host_id', auth()->id())
                ->whereIn('status', ['validated', 'closed', 'rejected'])
                ->orderByDesc('approved_at')
                ->limit(8)
                ->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Visitor Approvals</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Visitors asking to see you</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Guard holds a visitor at the gate until you approve or deny the visit here.</p>
    </section>

    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pendingForMe as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->driver_name }}{{ $gate->vendor_name ? ' · '.$gate->vendor_name : '' }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->material }} · {{ $gate->driver_phone ?? $gate->vehicle_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">At the gate since {{ $gate->created_at->format('d M, H:i') }}</div>
                    </div>
                    @if ($denying !== $gate->id)
                        <div class="flex gap-2 shrink-0">
                            <button wire:click="approve({{ $gate->id }})" wire:loading.attr="disabled" wire:target="approve({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--status-good);">Approve</button>
                            <button wire:click="startDeny({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--status-critical); color: var(--status-critical);">Deny</button>
                        </div>
                    @endif
                </div>

                @if ($denying === $gate->id)
                    <div class="mt-3 flex flex-col sm:flex-row gap-2 items-start">
                        <input wire:model="denyReason" class="rounded-lg border px-3 py-2 text-sm flex-1" style="border-color: var(--border);" placeholder="Reason (optional)" />
                        <div class="flex gap-2">
                            <button wire:click="deny({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">Confirm deny</button>
                            <button wire:click="cancelDeny" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No one's waiting to see you right now.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Recently decided</h2>
    <div class="flex flex-col gap-2">
        @forelse ($decidedByMe as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->driver_name }}</div>
                    <span class="text-xs font-medium" style="color: {{ $gate->status === 'rejected' ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $gate->status === 'rejected' ? 'Denied' : 'Approved' }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->material }} · {{ $gate->approved_at?->format('d M, H:i') }}</div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No decisions yet.</div>
        @endforelse
    </div>
</div>
