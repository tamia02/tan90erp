<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use App\Support\GateStatusLabels;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// New gate between Guard's save and dock assignment: previously an inward
// entry with no validation issues auto-flipped straight to "validated" with
// no human ever looking at it. The business flow requires Store Manager to
// explicitly approve every inward entry (issues or not) before it becomes
// eligible for a dock -- see GateValidationService/bill-scan.blade.php,
// which now always leaves a fresh inward entry at pending_validation.
new #[Layout('layouts.app')] class extends Component
{
    public function approve(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'pending_validation') {
            return;
        }

        if ($gate->hasBlockingOpenIssues()) {
            $this->addError('blocked', "{$gate->gate_no} still has an open hard-fail/red-flag issue — resolve it on Validation Issues first.");

            return;
        }

        $gate->update([
            'status' => 'validated',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        AuditLogger::log('Entry approved', "{$gate->gate_no} approved by ".auth()->user()->name, $gate);
    }

    public function with(): array
    {
        return [
            'pending' => GateEntry::where('entry_type', 'inward')
                ->where('status', 'pending_validation')
                ->with('validationIssues')
                ->orderBy('created_at')
                ->get(),
            'recentlyApproved' => GateEntry::where('entry_type', 'inward')
                ->whereNotNull('approved_at')
                ->with('approvedBy')
                ->orderByDesc('approved_at')
                ->limit(8)
                ->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Entry Approvals</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every inward gate entry waits here until you approve it — only then does it become eligible for a dock.</p>
    </section>

    @error('blocked')
        <div class="rounded-lg border p-3 mb-4 text-sm" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div>
    @enderror

    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pending as $gate)
            @php $blocked = $gate->hasBlockingOpenIssues(); @endphp
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->po_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->vendor_name }} · {{ $gate->material }} · {{ $gate->vehicle_number }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Arrived {{ $gate->created_at->format('d M, H:i') }}</div>
                    </a>
                    @if ($blocked)
                        <a href="{{ route('validation.issues') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium border shrink-0" style="border-color: var(--status-critical); color: var(--status-critical);">Resolve issues first →</a>
                    @else
                        <button wire:click="approve({{ $gate->id }})" wire:loading.attr="disabled" wire:target="approve({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Approve entry</button>
                    @endif
                </div>
                @if ($gate->validationIssues->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5 mt-3">
                        @foreach ($gate->validationIssues as $issue)
                            <span class="text-xs font-medium px-2 py-0.5 rounded" style="background: {{ $issue->severity === 'hardFail' ? 'var(--status-critical-bg)' : ($issue->severity === 'redFlag' ? 'var(--status-warning-bg)' : 'var(--surface-2)') }}; color: {{ $issue->severity === 'hardFail' ? 'var(--status-critical)' : ($issue->severity === 'redFlag' ? 'var(--status-warning)' : 'var(--text-muted)') }};">{{ $issue->title }} · {{ $issue->status }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for approval.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Recently approved</h2>
    <div class="flex flex-col gap-2">
        @forelse ($recentlyApproved as $gate)
            <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ GateStatusLabels::label($gate->status) }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · Approved by {{ $gate->approvedBy?->name ?? '—' }} on {{ $gate->approved_at?->format('d M, H:i') }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No approvals recorded yet.</div>
        @endforelse
    </div>
</div>
