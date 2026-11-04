<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $search = '';
    public ?string $sku = null;
    public ?string $bin = null;
    public ?string $po = null;

    public function mount(): void
    {
        $this->sku = request()->string('sku')->trim()->toString() ?: null;
        $this->bin = request()->string('bin')->trim()->toString() ?: null;
        $this->po = request()->string('po')->trim()->toString() ?: null;
        $this->search = $this->po ?: $this->sku ?: '';
    }

    public function with(): array
    {
        $term = trim($this->search);
        $movements = LedgerEntry::with('gateEntry')
            ->when($this->sku, fn ($query) => $query->where('sku', $this->sku))
            ->when($this->bin, fn ($query) => $query->where('bin', $this->bin))
            ->when($this->po, fn ($query) => $query->whereHas('gateEntry', fn ($gate) => $gate->where('po_number', $this->po)))
            ->when($term !== '', function ($query) use ($term): void {
                $like = "%{$term}%";
                $query->where(function ($nested) use ($like): void {
                    $nested->where('sku', 'like', $like)
                        ->orWhere('bin', 'like', $like)
                        ->orWhere('bucket', 'like', $like)
                        ->orWhereHas('gateEntry', fn ($gate) => $gate->where('po_number', 'like', $like)->orWhere('gate_no', 'like', $like));
                });
            })
            ->latest('created_at')
            ->limit(250)
            ->get();

        // Keep the detail query bounded, but calculate the balance in SQL so
        // the page does not load every historical ledger row into PHP.
        $available = LedgerEntry::where('bucket', 'available')
            ->selectRaw('sku, bin, SUM(qty) as total_qty')
            ->groupBy('sku', 'bin')
            ->get()
            ->keyBy(fn ($row) => $row->sku.'|'.$row->bin)
            ->map(fn ($row) => (int) $row->total_qty);

        return compact('movements', 'available');
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <div class="flex items-start justify-between gap-3 flex-wrap"><div><h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Stock Ledger History</h1><p class="text-sm mt-1" style="color: var(--text-secondary);">Every inventory movement is traceable back to its SKU, gate, PO and timestamp. Click a reference to open the complete receipt.</p>@if($sku || $bin || $po)<p class="text-xs mt-2 font-semibold" style="color:var(--brand);">Filtered to {{ $po ? 'PO '.$po : trim(($sku ?: '').($bin ? ' · '.$bin : '')) }}</p>@endif</div><a href="{{ route('grn.stock-balance') }}" wire:navigate class="min-h-11 inline-flex items-center rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Back to Stock Balance</a></div>
    </section>

    <section class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-col sm:flex-row gap-2 p-3 border-b" style="border-color: var(--border);"><label class="sr-only" for="ledger-history-search">Search stock history</label><input id="ledger-history-search" wire:model.live.debounce.250ms="search" class="min-h-11 flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search item, bin, PO or gate" /><span class="self-center text-xs" style="color: var(--text-muted);">{{ $movements->count() }} movements shown</span></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-sm"><thead><tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);"><th class="px-4 py-3">Item Name / SKU</th><th class="px-4 py-3">Updated Quantity</th><th class="px-4 py-3">Unit Price</th><th class="px-4 py-3">Movement Value</th><th class="px-4 py-3">Timestamp</th><th class="px-4 py-3">Reference / PO</th><th class="px-4 py-3">Total Available Quantity</th></tr></thead><tbody>
            @forelse ($movements as $movement)
                @php($inbound = (int) $movement->qty >= 0)
                <tr style="border-top: 1px solid var(--border);"><td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $movement->sku }}<div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $movement->bin }}</div></td><td class="px-4 py-3 font-semibold" style="color: {{ $inbound ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $inbound ? '+' : '−' }}{{ abs((int) $movement->qty) }} {{ $inbound ? 'In' : 'Out' }}</td><td class="px-4 py-3">{{ $movement->unit_price !== null ? '₹'.number_format((float) $movement->unit_price, 4) : '—' }}</td><td class="px-4 py-3">{{ $movement->extended_value !== null ? '₹'.number_format((float) $movement->extended_value, 2) : '—' }}</td><td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $movement->created_at?->format('d M Y, H:i') ?: '—' }}</td><td class="px-4 py-3"><a href="{{ $movement->gateEntry ? route('gate-entries.show', $movement->gateEntry) : '#' }}" wire:navigate class="font-semibold hover:underline" style="color: var(--brand);">{{ $movement->gateEntry?->po_number ?: $movement->gateEntry?->gate_no ?: '—' }}</a><div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $movement->gateEntry?->gate_no ?: 'No gate reference' }}</div></td><td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $available[$movement->sku.'|'.$movement->bin] ?? 0 }}</td></tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center" style="color: var(--text-muted);">No inventory movements match this search.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
