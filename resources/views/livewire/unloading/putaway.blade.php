<?php

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\LedgerEntry;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
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
    public array $finalBins = [];

    public function startConfirm(int $gateId): void
    {
        $gate = GateEntry::with(['grnRecord.lines', 'qcResult.lines'])->findOrFail($gateId);
        $this->confirming = $gateId;
        $this->finalBin = $gate->grnRecord?->suggested_bin ?: ($gate->final_bin ?? '');
        $lines = $gate->grnRecord?->lines?->isNotEmpty() ? $gate->grnRecord->lines : ($gate->qcResult?->lines ?? collect());
        $this->finalBins = $lines->map(fn ($line) => (string) ($line->suggested_bin ?: ($gate->final_bin === 'Multiple bins' ? '' : $gate->final_bin)))->values()->all();
    }

    public function cancelConfirm(): void
    {
        $this->reset(['confirming', 'finalBin', 'finalBins']);
    }

    public function confirmPutaway(int $gateId): void
    {
        $user = auth()->user();
        $canConfirm = $user?->holdsRole(Role::StoreExec)
            || ($user && app(\App\Services\Access\AccessControlService::class)->can($user, 'unloading.records.manage'));
        if (! $canConfirm) {
            session()->flash('error', 'Only the Store Executive can confirm the physical putaway bin.');
            $this->cancelConfirm();

            return;
        }

        $gate = GateEntry::with(['grnRecord.lines', 'qcResult.lines'])->findOrFail($gateId);

        $isGrnPosted = $gate->status === 'grn_posted' && $gate->grnRecord?->posted;
        $isQcHoldPutaway = $gate->status === 'qc_done'
            && ! $gate->grnRecord?->posted
            && (int) ($gate->qcResult?->qc_hold_qty ?? 0) > 0;

        if (! $isGrnPosted && ! $isQcHoldPutaway) {
            $this->cancelConfirm();

            return;
        }

        $lines = $gate->grnRecord?->lines?->isNotEmpty() ? $gate->grnRecord->lines : ($gate->qcResult?->lines ?? collect());
        $multiSku = $lines->count() > 1;
        if ($multiSku) {
            $rules = ['finalBins' => ['required', 'array', 'size:'.$lines->count()]];
            foreach ($lines->keys() as $index) $rules["finalBins.{$index}"] = ['required', 'string', 'max:255'];
            $this->validate($rules);
        } else {
            $this->validate(['finalBin' => ['required', 'string', 'max:255']]);
        }

        if ($gate->putaway_completed_at) {
            session()->flash('success', "Putaway is already recorded for {$gate->gate_no}; finish GRN after the QC hold is resolved.");
            $this->cancelConfirm();

            return;
        }

        // For a held QC receipt this is a physical-location confirmation only.
        // Do not create ledger/finance rows until Store Manager posts the GRN
        // after QC resolves the hold.
        if ($isQcHoldPutaway) {
            $lineBins = [];
            if ($multiSku) {
                foreach ($lines->values() as $index => $line) {
                    $bin = trim((string) $this->finalBins[$index]);
                    $line->update(['suggested_bin' => $bin]);
                    $lineBins[] = $line->sku.': '.$bin;
                }
            } else {
                $lineBins[] = ($lines->first()?->sku ?? $gate->qcResult?->sku).': '.$this->finalBin;
            }
            $normalizedBins = array_map('trim', $this->finalBins);
            $parentBin = $multiSku && count(array_unique($normalizedBins)) > 1 ? 'Multiple bins' : ($multiSku ? (string) ($normalizedBins[0] ?? '') : $this->finalBin);
            $gate->update([
                'putaway_by' => auth()->id(),
                'putaway_completed_at' => now(),
                'final_bin' => $parentBin,
                'store_exec_verified_by' => $gate->store_exec_verified_by ?: auth()->id(),
                'store_exec_verified_at' => $gate->store_exec_verified_at ?: now(),
            ]);

            AuditLogger::log('Putaway recorded while QC hold is pending', "{$gate->gate_no} · ".implode(' · ', $lineBins), $gate);
            session()->flash('success', "Putaway recorded for {$gate->gate_no}. GRN remains pending until QC hold is resolved.");
            $this->cancelConfirm();

            return;
        }

        $relocations = [];
        if ($multiSku) {
            foreach ($lines->values() as $index => $line) {
                $sourceBin = (string) ($line->suggested_bin ?: $gate->grnRecord->suggested_bin);
                $targetBin = trim((string) $this->finalBins[$index]);
                $relocations[] = ['sku' => $line->sku, 'from' => $sourceBin, 'to' => $targetBin, 'line' => $line];
            }
        } else {
            $relocations[] = ['sku' => $lines->first()?->sku ?? $gate->grnRecord->sku, 'from' => (string) $gate->grnRecord->suggested_bin, 'to' => $this->finalBin, 'line' => $lines->first()];
        }
        $finalBins = collect($relocations)->pluck('to')->unique()->values();
        $parentFinalBin = $finalBins->count() > 1 ? 'Multiple bins' : (string) $finalBins->first();
        DB::transaction(function () use ($relocations, $gate, $parentFinalBin): void {
            foreach ($relocations as $relocation) {
                $suggestedBin = $relocation['from'];
                $targetBin = $relocation['to'];
                if ($targetBin === $suggestedBin) {
                    $relocation['line']?->update(['suggested_bin' => $targetBin]);
                    continue;
                }
                // Relocate only this SKU's GRN entries. Each row keeps its own
                // destination bin; no cross-SKU bin assignment is possible.
                LedgerEntry::where('gate_entry_id', $gate->id)->where('sku', $relocation['sku'])->where('bin', $suggestedBin)->get()
                    ->each(function (LedgerEntry $line) use ($targetBin) {
                        $value = (float) ($line->extended_value ?? ((int) $line->qty * (float) ($line->unit_price ?? 0)));
                        LedgerEntry::create(['gate_entry_id' => $line->gate_entry_id, 'warehouse_id' => $line->warehouse_id, 'sku' => $line->sku, 'bin' => $line->bin, 'bucket' => $line->bucket, 'qty' => -$line->qty, 'unit_price' => $line->unit_price, 'extended_value' => -$value]);
                        LedgerEntry::create(['gate_entry_id' => $line->gate_entry_id, 'warehouse_id' => $line->warehouse_id, 'sku' => $line->sku, 'bin' => $targetBin, 'bucket' => $line->bucket, 'qty' => $line->qty, 'unit_price' => $line->unit_price, 'extended_value' => $value]);
                    });
                $relocation['line']?->update(['suggested_bin' => $targetBin]);
            }

            $gate->update([
                'status' => 'closed',
                'putaway_by' => auth()->id(),
                'putaway_completed_at' => now(),
                'final_bin' => $parentFinalBin,
                'store_exec_verified_by' => $gate->store_exec_verified_by ?: auth()->id(),
                'store_exec_verified_at' => $gate->store_exec_verified_at ?: now(),
            ]);
        });

        AuditLogger::log('Putaway complete, entry closed', "{$gate->gate_no} · ".implode(' · ', collect($relocations)->map(fn ($r) => $r['sku'].': '.$r['to'])->all()), $gate);
        app(\App\Services\OperationalEventMailer::class)->notify(
            "Putaway completed for {$gate->gate_no}",
            "Store Executive completed bin putaway for {$gate->gate_no} (PO {$gate->po_number}). The inward receipt is now closed.",
            $gate->location_id ?: \App\Support\SiteLocation::idForName($gate->location),
            [\App\Enums\Role::StoreManager, \App\Enums\Role::Finance],
            '/grn',
        );
        session()->flash('success', "Putaway confirmed for {$gate->gate_no}; the inward entry is closed.");

        $this->cancelConfirm();
    }

    public function with(): array
    {
        return [
            'pending' => GateEntry::with(['grnRecord.lines', 'qcResult.lines'])
                ->whereNull('putaway_completed_at')
                ->where(function ($query) {
                    $query->where(function ($query) {
                        $query->where('status', 'grn_posted')->whereHas('grnRecord', fn ($q) => $q->where('posted', true));
                    })->orWhere(function ($query) {
                        $query->where('status', 'qc_done')->whereHas('qcResult', fn ($q) => $q->where('qc_hold_qty', '>', 0));
                    });
                })
                ->orderByDesc('updated_at')
                ->get(),
                // Putaway work is operationally reviewed by the latest record
                // update, not by the original gate-entry timestamp.
                'history' => GateEntry::whereNotNull('putaway_completed_at')->with(['grnRecord.lines', 'qcResult.lines', 'putawayBy'])->orderByDesc('updated_at')->limit(10)->get(),
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">{{ session('error') }}</div>
    @endif
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Putaway</h1>
    <p class="text-sm mt-1" style="color: var(--text-secondary);">Confirm the physical bin after unloading. QC-held receipts can be put away now; stock and vendor GRN remain pending until the hold is resolved.</p>
    </section>

    <div class="flex flex-col gap-2 mb-6">
        @forelse ($pending as $gate)
            @php($isQcHoldPutaway = ! $gate->grnRecord?->posted && (int) ($gate->qcResult?->qc_hold_qty ?? 0) > 0)
            <div id="putaway-gate-{{ $gate->id }}" class="rounded-lg border p-4" style="background: var(--surface-3); border-color: {{ $isQcHoldPutaway ? 'var(--status-warning)' : 'var(--border)' }};">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->grnRecord?->lines?->count() > 1 ? 'MULTI-SKU' : ($gate->grnRecord?->sku ?? $gate->qcResult?->sku) }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->vendor_name }} · Accepted {{ $gate->grnRecord?->accepted_qty ?? $gate->qcResult?->accepted_qty }} · Hold {{ $gate->qcResult?->qc_hold_qty ?? 0 }} · Suggested bin {{ $gate->grnRecord?->suggested_bin ?? $gate->final_bin ?? 'Not assigned' }}</div>
                        @if ($gate->grnRecord?->lines?->isNotEmpty())
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">@foreach ($gate->grnRecord->lines as $line){{ $line->sku }}: {{ $line->accepted_qty }} accepted{{ $loop->last ? '' : ' · ' }}@endforeach</div>
                        @endif
                        @if ($isQcHoldPutaway)
                            <div class="text-xs mt-1 font-semibold" style="color: var(--status-warning);">Putaway allowed now; GRN and vendor document remain pending until QC hold is resolved.</div>
                        @endif
                    </a>
                    @if ($confirming !== $gate->id && (auth()->user()?->holdsRole(Role::StoreExec) || app(\App\Services\Access\AccessControlService::class)->can(auth()->user(), 'unloading.records.manage')))
                        <button wire:click="startConfirm({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Confirm putaway</button>
                    @elseif ($confirming !== $gate->id)
                        <span class="rounded-lg px-3.5 py-2 text-xs font-semibold" style="background: var(--surface-2); color: var(--text-secondary);">Store Executive confirmation required</span>
                    @endif
                </div>

                @if ($confirming === $gate->id)
                    @php($putawayLines = $gate->grnRecord?->lines?->isNotEmpty() ? $gate->grnRecord->lines : ($gate->qcResult?->lines ?? collect()))
                    <div class="mt-4 flex flex-col gap-3 items-start">
                        @if ($putawayLines->count() > 1)
                            <div class="w-full rounded-lg border p-3" style="border-color:var(--border);background:var(--surface-2);">
                                <div class="mb-2 text-sm font-semibold" style="color:var(--text-primary);">Confirm a physical bin for each SKU</div>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @foreach ($putawayLines as $index => $line)
                                        <label class="flex flex-col gap-1.5 text-sm">
                                            <span class="font-medium" style="color:var(--text-primary);">{{ $line->sku }} · {{ $line->accepted_qty }} accepted</span>
                                            <input wire:model="finalBins.{{ $index }}" aria-label="Final bin for {{ $line->sku }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);" placeholder="e.g. BHW-{{ $line->sku }}-A1" />
                                            @error("finalBins.{$index}") <span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span> @enderror
                                        </label>
                                    @endforeach
                                </div>
                                @error('finalBins') <span class="mt-2 block text-xs" style="color:var(--status-critical);">{{ $message }}</span> @enderror
                            </div>
                        @else
                            <label class="flex w-full flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Final bin</span>
                                <input wire:model="finalBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. BHW-PCM-A1" />
                                @error('finalBin') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <div class="flex gap-2 sm:mt-6">
                            <button wire:click="confirmPutaway({{ $gate->id }})" wire:loading.attr="disabled" wire:target="confirmPutaway({{ $gate->id }})" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">{{ $isQcHoldPutaway ? 'Save Putaway · keep GRN pending' : 'Save & close entry' }}</button>
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
                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->grnRecord?->lines?->count() > 1 ? 'MULTI-SKU' : ($gate->grnRecord?->sku ?? $gate->qcResult?->sku) }}</div>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $gate->putaway_completed_at?->format('d M, H:i') }}</span>
                </div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $gate->vendor_name }} · bin {{ $gate->final_bin ?? $gate->grnRecord?->suggested_bin }} · by {{ $gate->putawayBy?->name ?? '—' }} · {{ $gate->grnRecord?->posted ? 'GRN posted' : 'GRN pending QC hold' }}</div>
                @if ($gate->grnRecord?->lines?->isNotEmpty())
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">@foreach ($gate->grnRecord->lines as $line){{ $line->sku }}: {{ $line->accepted_qty }} accepted{{ $loop->last ? '' : ' · ' }}@endforeach</div>
                @endif
            </a>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No putaway records yet.</div>
        @endforelse
    </div>
</div>
