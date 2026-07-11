<?php

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Services\GrnPostingService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $posting = null;
    public string $suggestedBin = '';

    public function openPost(int $gateId): void
    {
        $this->posting = $gateId;
        $this->suggestedBin = '';
    }

    public function post(): void
    {
        $this->validate(['suggestedBin' => ['required', 'string']]);

        $gate = GateEntry::findOrFail($this->posting);
        app(GrnPostingService::class)->post($gate, $this->suggestedBin);

        $this->reset(['posting', 'suggestedBin']);
    }

    public function with(): array
    {
        $queue = GateEntry::with('qcResult')->where('status', 'qc_done')->orderBy('created_at')->get();

        $purchaseOrders = PurchaseOrder::whereIn('po_number', $queue->pluck('po_number')->filter())
            ->with('lines')
            ->get()
            ->keyBy('po_number');

        return [
            'queue' => $queue,
            'purchaseOrders' => $purchaseOrders,
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">GRN Check</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Post the QC-checked split to stock and close the gate entry.</p>

    <div class="flex flex-col gap-3">
        @forelse ($queue as $g)
            @php
                $qc = $g->qcResult;
                $po = $purchaseOrders->get($g->po_number);
                $poLine = $po?->primaryLine();
            @endphp
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }} · {{ $qc?->sku }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            Accepted {{ $qc?->accepted_qty }} · Hold {{ $qc?->qc_hold_qty }} · Defective {{ $qc?->defective_qty }} · Rejected {{ $qc?->rejected_qty }}
                        </div>
                        @if ($qc?->qc_reasons)
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">QC notes: {{ $qc->qc_reasons }}</div>
                        @endif
                    </div>
                    @if ($posting !== $g->id)
                        <button wire:click="openPost({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Post GRN</button>
                    @endif
                </div>

                @if ($poLine)
                    <div class="mt-3 rounded-lg border p-3 text-xs" style="border-color: var(--border); background: var(--surface-2);">
                        <div class="font-semibold mb-1.5" style="color: var(--text-muted);">QC quantity vs PO price ({{ $g->po_number }})</div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <div><span style="color: var(--text-muted);">PO qty</span> · <span style="color: var(--text-primary);">{{ $poLine->quantity }}</span></div>
                            <div><span style="color: var(--text-muted);">List price</span> · <span style="color: var(--text-primary);">₹{{ number_format($poLine->list_price, 2) }}</span></div>
                            <div><span style="color: var(--text-muted);">Accepted value</span> · <span style="color: var(--status-good);">₹{{ number_format(($qc?->accepted_qty ?? 0) * $poLine->list_price, 2) }}</span></div>
                            <div><span style="color: var(--text-muted);">Qty variance</span> · <span style="color: {{ ($qc?->accepted_qty ?? 0) < $poLine->quantity ? 'var(--status-warning)' : 'var(--status-good)' }};">{{ ($qc?->accepted_qty ?? 0) - $poLine->quantity }}</span></div>
                        </div>
                    </div>
                @endif

                @if ($posting === $g->id)
                    <div class="mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Suggested bin</span>
                            <input wire:model="suggestedBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-A1" />
                        </label>
                        <button wire:click="post" class="mt-4 rounded-lg px-4 py-2 text-sm font-medium text-white" style="background: var(--brand);">Post GRN &amp; update stock</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for GRN posting.</div>
        @endforelse
    </div>
</div>
