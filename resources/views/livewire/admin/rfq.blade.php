<?php

use App\Models\Rfq;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editing = null;
    public string $quotedPrice = '';
    public string $adminNotes = '';

    public function edit(int $id): void
    {
        $rfq = Rfq::findOrFail($id);
        $this->editing = $rfq->id;
        $this->quotedPrice = (string) ($rfq->quoted_price ?? '');
        $this->adminNotes = (string) ($rfq->admin_notes ?? '');
    }

    public function markQuoted(int $id): void
    {
        $rfq = Rfq::findOrFail($id);

        if (in_array($rfq->status, ['closed', 'selected'])) {
            return;
        }

        $this->validate(['quotedPrice' => ['required', 'numeric', 'min:0']]);

        $rfq->update([
            'status' => 'quoted',
            'quoted_price' => $this->quotedPrice,
            'admin_notes' => $this->adminNotes ?: $rfq->admin_notes,
        ]);

        AuditLogger::log('RFQ quoted', "{$rfq->sku} · {$rfq->vendor_name} · ₹{$this->quotedPrice}", $rfq);

        $this->reset(['editing', 'quotedPrice', 'adminNotes']);
    }

    public function close(int $id): void
    {
        $rfq = Rfq::findOrFail($id);

        if (in_array($rfq->status, ['closed', 'selected'])) {
            return;
        }

        $rfq->update(['status' => 'closed', 'admin_notes' => $this->adminNotes ?: $rfq->admin_notes]);

        AuditLogger::log('RFQ closed', "{$rfq->sku} · {$rfq->vendor_name}", $rfq);

        $this->reset(['editing', 'quotedPrice', 'adminNotes']);
    }

    public string $technicalScore = '';

    public string $commercialScore = '';

    public function evaluate(int $id): void
    {
        $rfq = Rfq::findOrFail($id);

        $this->validate([
            'technicalScore' => ['required', 'integer', 'min:0', 'max:100'],
            'commercialScore' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $rfq->update([
            'technical_score' => $this->technicalScore,
            'commercial_score' => $this->commercialScore,
            'evaluated_by' => auth()->id(),
            'evaluated_at' => now(),
        ]);

        AuditLogger::log('RFQ evaluated', "{$rfq->sku} · {$rfq->vendor_name} · weighted {$rfq->weightedScore()}", $rfq);

        $this->reset(['technicalScore', 'commercialScore']);
    }

    public function with(): array
    {
        return ['rfqs' => Rfq::orderByDesc('created_at')->get()];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">RFQs</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Vendor requests for quotation — quote a price or close out each request.</p>

    <div class="flex flex-col gap-3">
        @forelse ($rfqs as $r)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->sku }} · {{ $r->vendor_name }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Quantity {{ $r->quantity }} · {{ $r->created_at->format('d M, Y') }}</div>
                    </div>
                    <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $r->status === 'selected' ? 'var(--status-good)' : ($r->status === 'quoted' ? 'var(--text-primary)' : ($r->status === 'closed' ? 'var(--text-muted)' : 'var(--status-warning)')) }};">{{ $r->status }}</span>
                </div>

                @if ($r->notes)
                    <div class="text-xs mt-2" style="color: var(--text-secondary);">Vendor notes: {{ $r->notes }}</div>
                @endif
                @if ($r->quoted_price)
                    <div class="text-xs mt-1" style="color: var(--status-good);">Quoted price: ₹{{ number_format($r->quoted_price, 2) }}</div>
                @endif
                @if ($r->admin_notes)
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Admin notes: {{ $r->admin_notes }}</div>
                @endif
                @if ($r->weightedScore() !== null)
                    <div class="text-xs mt-1" style="color: var(--text-primary);">Evaluation: technical {{ $r->technical_score }}/100 · commercial {{ $r->commercial_score }}/100 · weighted <strong>{{ $r->weightedScore() }}</strong></div>
                @endif

                @if ($editing === $r->id)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Quoted price</span>
                            <input wire:model="quotedPrice" type="number" step="0.01" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('quotedPrice') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Admin notes</span>
                            <input wire:model="adminNotes" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                        </label>
                    </div>
                @endif

                @if ($r->status === 'quoted')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Technical score (0-100)</span>
                            <input wire:model="technicalScore" type="number" min="0" max="100" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('technicalScore') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Commercial score (0-100)</span>
                            <input wire:model="commercialScore" type="number" min="0" max="100" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                            @error('commercialScore') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        </label>
                    </div>
                @endif

                <div class="flex gap-2 mt-3">
                    @if ($editing !== $r->id && ! in_array($r->status, ['closed', 'selected']))
                        <button wire:click="edit({{ $r->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-primary);">Add quote / notes</button>
                    @endif
                    @if ($editing === $r->id)
                        <button wire:click="markQuoted({{ $r->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Save quote</button>
                    @endif
                    @if ($r->status === 'quoted')
                        <button wire:click="evaluate({{ $r->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--primary); color: var(--primary);">Save evaluation</button>
                    @endif
                    @if (! in_array($r->status, ['closed', 'selected']))
                        <button wire:click="close({{ $r->id }})" wire:confirm="Close this RFQ?" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Close</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No RFQs submitted yet.</div>
        @endforelse
    </div>
</div>
