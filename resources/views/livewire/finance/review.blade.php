<?php

use App\Models\FinanceRecord;
use App\Services\AuditLogger;
use App\Services\ZohoInventoryService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editing = null;
    public string $notes = '';

    public function setStatus(int $id, string $status): void
    {
        $record = FinanceRecord::findOrFail($id);

        if ($status === 'cleared' && $record->match_status !== 'matched') {
            $this->addError('match', 'Only invoices that have passed the three-way match can be cleared for payment.');

            return;
        }

        $wasCleared = $record->vendor_status === 'cleared';

        $record->update(['vendor_status' => $status, 'notes' => $this->notes ?: $record->notes]);

        AuditLogger::log("Vendor status set to {$status}", (string) $record->gate_entry_id, $record);

        // Previously nothing told Zoho a vendor was actually paid — Bills
        // push automatically on every FinanceRecord save, but "cleared"
        // (payment made) was never itself a Zoho event. Only push once, the
        // moment it first becomes cleared, not on every subsequent save.
        if ($status === 'cleared' && ! $wasCleared) {
            $inventory = app(ZohoInventoryService::class);
            if ($inventory->isActive()) {
                try {
                    if (! $inventory->pushPaymentMade($record)) {
                        Log::warning('Zoho Inventory payment push failed from Finance Review', [
                            'finance_record_id' => $record->id,
                            'error' => $inventory->lastError(),
                        ]);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Zoho Inventory payment push exception from Finance Review', [
                        'finance_record_id' => $record->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->reset(['editing', 'notes']);
    }

    public function with(): array
    {
        return ['records' => FinanceRecord::with(['gateEntry', 'debitNotes'])->orderByDesc('created_at')->get()];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Finance Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Finance Review</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every payable, deductions and vendor closure status.</p>
    </section>

    @error('match') <div class="mb-4 p-3 rounded text-sm text-red-800 bg-red-100">{{ $message }}</div> @enderror

    <div class="flex flex-col gap-3">
        @forelse ($records as $r)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <a href="{{ $r->gateEntry ? route('gate-entries.show', $r->gateEntry) : '#' }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }} · {{ $r->vendor_name }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Invoice {{ $r->invoice_number ?? '—' }} · Rate ₹{{ $r->rate_per_unit }}/unit</div>
                    </a>
                    <div class="flex gap-2">
                        <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $r->match_status === 'matched' ? 'var(--status-good)' : ($r->match_status === 'exception' ? 'var(--status-critical)' : 'var(--text-muted)') }};">match: {{ $r->match_status }}</span>
                        <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $r->vendor_status === 'cleared' ? 'var(--status-good)' : ($r->vendor_status === 'hold' ? 'var(--status-critical)' : 'var(--status-warning)') }};">{{ $r->vendor_status }}</span>
                    </div>
                </div>
                @if ($r->match_status === 'exception' && $r->match_notes)
                    <div class="text-xs mt-2" style="color: var(--status-critical);">{{ $r->match_notes }}</div>
                @endif
                @if ($r->debitNotes->isNotEmpty())
                    <div class="text-xs mt-2" style="color: var(--text-secondary);">
                        Debit notes: {{ $r->debitNotes->map(fn ($d) => "{$d->reason} (₹".number_format($d->amount, 2).")")->implode(', ') }}
                    </div>
                @endif
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-xs">
                    <div><div style="color: var(--text-muted);">Invoice Value</div><div class="font-medium" style="color: var(--text-primary);">₹{{ number_format($r->invoice_value, 2) }}</div></div>
                    <div><div style="color: var(--text-muted);">Accepted Value</div><div class="font-medium" style="color: var(--text-primary);">₹{{ number_format($r->accepted_value, 2) }}</div></div>
                    <div><div style="color: var(--text-muted);">Deductions</div><div class="font-medium" style="color: var(--status-critical);">₹{{ number_format($r->deduction_defective + $r->deduction_rejected + $r->deduction_missing, 2) }}</div></div>
                    <div><div style="color: var(--text-muted);">Final Payable</div><div class="font-medium" style="color: var(--text-primary);">₹{{ number_format($r->final_payable, 2) }}</div></div>
                </div>
                @if ($r->notes)
                    <div class="text-xs mt-2" style="color: var(--text-secondary);">{{ $r->notes }}</div>
                @endif

                @if ($editing === $r->id)
                    <div class="mt-3">
                        <textarea wire:model="notes" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Notes (optional)"></textarea>
                    </div>
                @endif

                <div class="flex gap-2 mt-3">
                    @if ($editing !== $r->id)
                        <button wire:click="$set('editing', {{ $r->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">Add note</button>
                    @endif
                    <button
                        wire:click="setStatus({{ $r->id }}, 'cleared')"
                        @disabled($r->match_status !== 'matched')
                        title="{{ $r->match_status !== 'matched' ? 'Blocked until the three-way match passes' : '' }}"
                        class="text-xs font-medium rounded-lg px-2.5 py-1.5 border disabled:opacity-40 disabled:cursor-not-allowed"
                        style="border-color: var(--status-good); color: var(--status-good);"
                    >Clear</button>
                    <button wire:click="setStatus({{ $r->id }}, 'hold')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Hold</button>
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No finance records yet.</div>
        @endforelse
    </div>
</div>
