<?php

use App\Models\GateEntry;
use App\Services\AuditLogger;
use App\Support\GateStatusLabels;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// New gate between Guard's save and the next stage: previously a clean
// inward entry auto-flipped straight to "validated" with no human ever
// looking at it, and outward entries had no workflow at all beyond Guard's
// save. Both journeys now require Store Manager to explicitly approve --
// see GateValidationService/bill-scan.blade.php, which always leaves a
// fresh inward or outward entry at pending_validation.
new #[Layout('layouts.app')] class extends Component
{
    public ?int $assigningOutward = null;
    public string $outwardDock = '';
    public string $loadingWindowStart = '';
    public string $loadingWindowEnd = '';
    public string $expectedDeliveryDate = '';

    public array $outwardDocks = ['Dispatch Bay 1', 'Dispatch Bay 2', 'Dispatch Bay 3', 'Dispatch Bay 4'];

    private function occupiedOutwardDocks(): array
    {
        return GateEntry::whereIn('status', ['dock_assigned', 'loaded'])->where('entry_type', 'outward')->pluck('loading_dock')->filter()->all();
    }

    private function availableOutwardDocks(): array
    {
        return array_values(array_diff($this->outwardDocks, $this->occupiedOutwardDocks()));
    }

    public function approve(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'pending_validation' || $gate->entry_type !== 'inward') {
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

    public function openOutwardApproval(int $gateId): void
    {
        $this->assigningOutward = $gateId;
        $this->outwardDock = $this->availableOutwardDocks()[0] ?? '';
        $this->loadingWindowStart = '';
        $this->loadingWindowEnd = '';
        $this->expectedDeliveryDate = '';
    }

    public function cancelOutwardApproval(): void
    {
        $this->reset(['assigningOutward', 'outwardDock', 'loadingWindowStart', 'loadingWindowEnd', 'expectedDeliveryDate']);
    }

    // One motion covers the flow's "Store manager approves and a dock is
    // assigned" step for outward -- unlike inward, where approval and dock
    // assignment stay two separate actions/roles (Store Manager approves,
    // Store Exec assigns the dock at Loading Desk).
    public function approveOutward(int $gateId): void
    {
        $gate = GateEntry::findOrFail($gateId);

        if ($gate->status !== 'pending_validation' || $gate->entry_type !== 'outward') {
            $this->cancelOutwardApproval();

            return;
        }

        if ($gate->hasBlockingOpenIssues()) {
            $this->addError('blocked', "{$gate->gate_no} still has an open hard-fail/red-flag issue.");

            return;
        }

        $this->validate([
            'outwardDock' => ['required', 'string', Rule::in($this->availableOutwardDocks())],
            'loadingWindowStart' => ['required', 'date'],
            'loadingWindowEnd' => ['required', 'date', 'after:loadingWindowStart'],
            'expectedDeliveryDate' => ['required', 'date'],
        ]);

        $gate->update([
            'status' => 'dock_assigned',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'loading_dock' => $this->outwardDock,
            'dock_assigned_at' => now(),
            'loading_window_start' => $this->loadingWindowStart,
            'loading_window_end' => $this->loadingWindowEnd,
            'expected_delivery_date' => $this->expectedDeliveryDate,
        ]);

        AuditLogger::log('Outward entry approved & dock assigned', "{$gate->gate_no} · {$this->outwardDock} · EDD ".\Illuminate\Support\Carbon::parse($this->expectedDeliveryDate)->format('d M Y'), $gate);

        $this->cancelOutwardApproval();
    }

    public function with(): array
    {
        return [
            'pendingInward' => GateEntry::where('entry_type', 'inward')
                ->where('status', 'pending_validation')
                ->with('validationIssues')
                ->orderBy('created_at')
                ->get(),
            'pendingOutward' => GateEntry::where('entry_type', 'outward')
                ->where('status', 'pending_validation')
                ->with('validationIssues')
                ->orderBy('created_at')
                ->get(),
            'availableOutwardDocks' => $this->availableOutwardDocks(),
            'recentlyApproved' => GateEntry::whereIn('entry_type', ['inward', 'outward'])
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
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every inward and outward gate entry waits here until you approve it.</p>
    </section>

    @error('blocked')
        <div class="rounded-lg border p-3 mb-4 text-sm" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">{{ $message }}</div>
    @enderror

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Inward — awaiting approval</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pendingInward as $gate)
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
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">Nothing waiting for approval.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Outward — awaiting approval &amp; dock</h2>
    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pendingOutward as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->vendor_name }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->material }} · {{ $gate->vehicle_number }} · Arrived {{ $gate->created_at->format('d M, H:i') }}</div>
                    </a>
                    @if ($assigningOutward !== $gate->id)
                        <button wire:click="openOutwardApproval({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Approve &amp; assign dock</button>
                    @endif
                </div>

                @if ($assigningOutward === $gate->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Dispatch bay</span>
                            <select wire:model="outwardDock" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                                <option value="">Select…</option>
                                @foreach ($availableOutwardDocks as $d) <option value="{{ $d }}">{{ $d }}</option> @endforeach
                            </select>
                            @error('outwardDock') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Expected delivery date</span>
                            <input wire:model="expectedDeliveryDate" type="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('expectedDeliveryDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Loading window start</span>
                            <input wire:model="loadingWindowStart" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('loadingWindowStart') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Loading window end</span>
                            <input wire:model="loadingWindowEnd" type="datetime-local" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('loadingWindowEnd') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="sm:col-span-2 flex gap-2">
                            <button wire:click="approveOutward({{ $gate->id }})" wire:loading.attr="disabled" wire:target="approveOutward({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Confirm approval</button>
                            <button wire:click="cancelOutwardApproval" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-6" style="color: var(--text-muted);">Nothing waiting for approval.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Recently approved</h2>
    <div class="flex flex-col gap-2">
        @forelse ($recentlyApproved as $gate)
            <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ ucfirst($gate->entry_type) }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ GateStatusLabels::label($gate->status) }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · Approved by {{ $gate->approvedBy?->name ?? '—' }} on {{ $gate->approved_at?->format('d M, H:i') }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No approvals recorded yet.</div>
        @endforelse
    </div>
</div>
