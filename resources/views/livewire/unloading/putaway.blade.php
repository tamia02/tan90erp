<?php

use App\Models\GateEntry;
use App\Models\LedgerEntry;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Closes the gap between "GRN posted" and "gate entry closed": GrnPostingService
// used to close the entry the instant GRN was posted, with the Store
// Manager's typed-in "suggested bin" written straight to the ledger as if
// the goods were already there. Nobody ever physically confirmed the goods
// reached a bin. Store Exec now does that here -- confirm the suggested bin
// as-is, or relocate to a different one -- before the entry is truly closed.
new #[Layout('layouts.app')] class extends Component
{
    public ?int $confirming = null;
    public string $finalBin = '';

    public function startConfirm(int $gateId): void
    {
        $gate = GateEntry::with('grnRecord')->findOrFail($gateId);
        $this->confirming = $gateId;
        $this->finalBin = $gate->grnRecord?->suggested_bin ?? '';
    }

    public function cancelConfirm(): void
    {
        $this->reset(['confirming', 'finalBin']);
    }

    public function confirmPutaway(int $gateId): void
    {
        $this->validate(['finalBin' => ['required', 'string', 'max:255']]);

        $gate = GateEntry::with('grnRecord')->findOrFail($gateId);

        if ($gate->status !== 'grn_posted' || ! $gate->grnRecord) {
            $this->cancelConfirm();

            return;
        }

        $suggestedBin = $gate->grnRecord->suggested_bin;

        if ($this->finalBin !== $suggestedBin) {
            // Relocate every ledger line this GRN posted -- same bin-to-bin
            // technique Shelf & Bin already uses (negative out, positive in),
            // scoped to just this gate entry's own lines.
            LedgerEntry::where('gate_entry_id', $gate->id)->where('bin', $suggestedBin)->get()
                ->each(function (LedgerEntry $line) {
                    LedgerEntry::create(['gate_entry_id' => $line->gate_entry_id, 'sku' => $line->sku, 'bin' => $line->bin, 'bucket' => $line->bucket, 'qty' => -$line->qty]);
                    LedgerEntry::create(['gate_entry_id' => $line->gate_entry_id, 'sku' => $line->sku, 'bin' => $this->finalBin, 'bucket' => $line->bucket, 'qty' => $line->qty]);
                });
        }

        $gate->update([
            'status' => 'closed',
            'putaway_by' => auth()->id(),
            'putaway_completed_at' => now(),
            'final_bin' => $this->finalBin,
        ]);

        AuditLogger::log('Putaway complete, entry closed', "{$gate->gate_no} · bin {$this->finalBin}".($this->finalBin !== $suggestedBin ? " (relocated from {$suggestedBin})" : ''), $gate);

        $this->cancelConfirm();
    }

    public function with(): array
    {
        return [
            'pending' => GateEntry::where('status', 'grn_posted')->with('grnRecord')->orderByDesc('updated_at')->get(),
            'history' => GateEntry::whereNotNull('putaway_completed_at')->with(['grnRecord', 'putawayBy'])->orderByDesc('putaway_completed_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Putaway</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">GRN-posted entries waiting for goods to actually reach their bin. Confirm the suggested bin, or relocate if it went somewhere else.</p>
    </section>

    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pending as $gate)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->grnRecord?->sku }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->vendor_name }} · Accepted {{ $gate->grnRecord?->accepted_qty }} · Suggested bin {{ $gate->grnRecord?->suggested_bin ?? '—' }}</div>
                    </a>
                    @if ($confirming !== $gate->id)
                        <button wire:click="startConfirm({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Confirm putaway</button>
                    @endif
                </div>

                @if ($confirming === $gate->id)
                    <div class="mt-4 flex flex-col sm:flex-row gap-2 items-start">
                        <label class="flex flex-col gap-1.5 text-sm flex-1">
                            <span class="font-medium" style="color: var(--text-primary);">Final bin</span>
                            <input wire:model="finalBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. BHW-PCM-A1" />
                            @error('finalBin') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <div class="flex gap-2 sm:mt-6">
                            <button wire:click="confirmPutaway({{ $gate->id }})" wire:loading.attr="disabled" wire:target="confirmPutaway({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Save &amp; close entry</button>
                            <button wire:click="cancelConfirm" class="rounded-lg px-3.5 py-2 text-sm font-medium border" style="border-color: var(--border); color: var(--text-primary);">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for putaway.</div>
        @endforelse
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">History</h2>
    <div class="flex flex-col gap-2">
        @forelse ($history as $gate)
            <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="block rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->grnRecord?->sku }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $gate->putaway_completed_at?->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · bin {{ $gate->final_bin ?? $gate->grnRecord?->suggested_bin }} · by {{ $gate->putawayBy?->name ?? '—' }}</div>
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No putaway records yet.</div>
        @endforelse
    </div>
</div>
