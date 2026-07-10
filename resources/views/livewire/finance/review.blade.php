<?php

use App\Models\FinanceRecord;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editing = null;
    public string $notes = '';

    public function setStatus(int $id, string $status): void
    {
        $record = FinanceRecord::findOrFail($id);
        $record->update(['vendor_status' => $status, 'notes' => $this->notes ?: $record->notes]);

        AuditLogger::log("Vendor status set to {$status}", (string) $record->gate_entry_id);

        $this->reset(['editing', 'notes']);
    }

    public function with(): array
    {
        return ['records' => FinanceRecord::with('gateEntry')->orderByDesc('created_at')->get()];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Finance Review</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every payable, deductions and vendor closure status.</p>

    <div class="flex flex-col gap-3">
        @forelse ($records as $r)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }} · {{ $r->vendor_name }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Invoice {{ $r->invoice_number ?? '—' }} · Rate ₹{{ $r->rate_per_unit }}/unit</div>
                    </div>
                    <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $r->vendor_status === 'cleared' ? 'var(--status-good)' : ($r->vendor_status === 'hold' ? 'var(--status-critical)' : 'var(--status-warning)') }};">{{ $r->vendor_status }}</span>
                </div>
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
                    <button wire:click="setStatus({{ $r->id }}, 'cleared')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Clear</button>
                    <button wire:click="setStatus({{ $r->id }}, 'hold')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Hold</button>
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No finance records yet.</div>
        @endforelse
    </div>
</div>
