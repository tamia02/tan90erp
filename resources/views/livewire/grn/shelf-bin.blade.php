<?php

use App\Models\LedgerEntry;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $fromBin = '';
    public string $toBin = '';
    public string $sku = '';
    public string $qty = '';
    public ?string $transferError = null;
    public ?string $selectedBin = null;

    public function transfer(): void
    {
        $this->transferError = null;
        $this->validate([
            'fromBin' => ['required', 'string'],
            'toBin' => ['required', 'string', 'different:fromBin'],
            'sku' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $sourceEntries = LedgerEntry::where('bin', $this->fromBin)->where('sku', $this->sku)->where('bucket', 'available')->get();
        $available = (int) $sourceEntries->sum('qty');
        $qty = (int) $this->qty;

        if ($qty > $available) {
            $this->transferError = "Only {$available} available for {$this->sku} in {$this->fromBin}.";

            return;
        }

        // Bin-to-bin putaway doesn't create new stock - it just relocates
        // existing received stock, so it reuses the gate entry that most
        // recently put this SKU into the source bin (ledger_entries.
        // gate_entry_id is required, not nullable).
        $gateEntryId = LedgerEntry::where('bin', $this->fromBin)->where('sku', $this->sku)->where('bucket', 'available')->where('qty', '>', 0)->latest()->value('gate_entry_id');
        $availableValue = (float) $sourceEntries->sum(fn (LedgerEntry $entry) => (float) ($entry->extended_value ?? ((int) $entry->qty * (float) ($entry->unit_price ?? 0))));
        $unitPrice = $available > 0 ? round($availableValue / $available, 4) : 0;
        $transferValue = round($qty * $unitPrice, 2);

        $warehouseId = $sourceEntries->first()?->warehouse_id;
        LedgerEntry::create(['gate_entry_id' => $gateEntryId, 'warehouse_id' => $warehouseId, 'sku' => $this->sku, 'bin' => $this->fromBin, 'bucket' => 'available', 'qty' => -$qty, 'unit_price' => $unitPrice, 'extended_value' => -$transferValue]);
        LedgerEntry::create(['gate_entry_id' => $gateEntryId, 'warehouse_id' => $warehouseId, 'sku' => $this->sku, 'bin' => $this->toBin, 'bucket' => 'available', 'qty' => $qty, 'unit_price' => $unitPrice, 'extended_value' => $transferValue]);

        AuditLogger::log('Bin transfer', "{$this->sku} · {$qty} · {$this->fromBin} → {$this->toBin}");

        $this->reset(['fromBin', 'toBin', 'sku', 'qty']);
    }

    public function openBin(string $bin): void
    {
        $this->selectedBin = $bin;
    }

    public function closeBin(): void
    {
        $this->selectedBin = null;
    }

    public function with(): array
    {
        $byBin = LedgerEntry::where('bucket', 'available')
            ->selectRaw('bin, sku, SUM(qty) as total_qty')
            ->groupBy('bin', 'sku')
            ->orderBy('bin')
            ->get()
            ->groupBy('bin')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->sku => (int) $row->total_qty])->filter(fn ($qty) => $qty > 0))
            ->filter(fn ($skus) => $skus->isNotEmpty());

        $fifo = collect();
        if ($this->selectedBin) {
            $entries = LedgerEntry::with('gateEntry')
                ->where('bin', $this->selectedBin)
                ->where('bucket', 'available')
                ->orderBy('created_at')
                ->get();

            $lotsBySku = [];
            foreach ($entries as $entry) {
                $sku = (string) $entry->sku;
                $qty = (int) $entry->qty;
                $lotsBySku[$sku] ??= [];

                if ($qty > 0) {
                    $lotsBySku[$sku][] = [
                        'remaining' => $qty,
                        'received' => $qty,
                        'unit_price' => $entry->unit_price,
                        'updated_at' => $entry->created_at?->format('d M Y, H:i'),
                        'po' => $entry->gateEntry?->po_number,
                        'gate' => $entry->gateEntry?->gate_no,
                    ];
                    continue;
                }

                $toConsume = abs($qty);
                foreach ($lotsBySku[$sku] as &$lot) {
                    if ($toConsume <= 0) {
                        break;
                    }
                    $used = min($lot['remaining'], $toConsume);
                    $lot['remaining'] -= $used;
                    $toConsume -= $used;
                }
                unset($lot);
            }

            $fifo = collect($lotsBySku)->map(function (array $lots, string $sku) {
                $availableLots = collect($lots)->filter(fn (array $lot) => $lot['remaining'] > 0)->values();

                return [
                    'sku' => $sku,
                    'quantity' => (int) $availableLots->sum('remaining'),
                    'lots' => $availableLots->map(fn (array $lot) => [
                        'qty' => $lot['remaining'],
                        'received' => $lot['received'],
                        'unit_price' => $lot['unit_price'],
                        'updated_at' => $lot['updated_at'],
                        'po' => $lot['po'],
                        'gate' => $lot['gate'],
                    ])->values(),
                ];
            })->filter(fn (array $row) => $row['quantity'] > 0)->values();
        }

        return ['byBin' => $byBin, 'fifo' => $fifo];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Shelf &amp; Bin</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">What's currently sitting in each bin, and putaway moves between bins.</p>
    </section>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Move stock between bins</h2>
        @if ($transferError)
            <div class="text-xs mb-2" style="color: var(--status-critical);">{{ $transferError }}</div>
        @endif
        <form wire:submit="transfer" class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <label class="flex flex-col gap-1 text-xs">
                <span class="font-medium" style="color: var(--text-primary);">SKU</span>
                <input wire:model="sku" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" placeholder="TNPM0050GMTP" />
                @error('sku') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs">
                <span class="font-medium" style="color: var(--text-primary);">From bin</span>
                <input wire:model="fromBin" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-A1" />
                @error('fromBin') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs">
                <span class="font-medium" style="color: var(--text-primary);">To bin</span>
                <input wire:model="toBin" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" placeholder="BHW-PCM-B2" />
                @error('toBin') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-xs">
                <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                <input wire:model="qty" type="number" min="1" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" />
                @error('qty') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="col-span-2 sm:col-span-4 rounded-lg px-3 py-2 text-sm font-medium text-white" style="background: var(--brand);">Move stock</button>
        </form>
    </div>

    @if ($selectedBin)
        <section class="rounded-lg border p-4 mb-4" style="background: var(--surface-3); border-color: var(--brand);">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div><div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Shelf / Bin details</div><h2 class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ $selectedBin }}</h2><p class="text-xs mt-1" style="color: var(--text-secondary);">FIFO view: oldest positive receipt appears first. Use the PO/gate reference to trace the stock movement.</p></div>
                <button type="button" wire:click="closeBin" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Close details</button>
            </div>
            <div class="overflow-x-auto mt-4"><table class="w-full min-w-[800px] text-sm"><thead><tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);"><th class="py-2 pr-3">SKU</th><th class="py-2 pr-3">Available quantity</th><th class="py-2 pr-3">FIFO lot remaining</th><th class="py-2 pr-3">Unit price</th><th class="py-2 pr-3">Reference / PO</th><th class="py-2">Updated</th></tr></thead><tbody>
                @forelse ($fifo as $row)
                    @foreach ($row['lots'] as $lot)
                        <tr style="border-top: 1px solid var(--border);"><td class="py-2 pr-3 font-medium" style="color: var(--text-primary);">{{ $row['sku'] }}</td><td class="py-2 pr-3">{{ $row['quantity'] }}</td><td class="py-2 pr-3">{{ $lot['qty'] }} <span class="text-xs" style="color: var(--text-muted);">of {{ $lot['received'] }}</span></td><td class="py-2 pr-3">{{ $lot['unit_price'] !== null ? '₹'.number_format((float) $lot['unit_price'], 4) : '—' }}</td><td class="py-2 pr-3">{{ $lot['po'] ?: $lot['gate'] ?: '—' }}</td><td class="py-2">{{ $lot['updated_at'] ?: '—' }}</td></tr>
                    @endforeach
                @empty
                    <tr><td colspan="6" class="py-6 text-center" style="color: var(--text-muted);">No available stock is currently stored in this bin.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @forelse ($byBin as $bin => $skus)
            <button type="button" wire:click="openBin(@js($bin))" class="rounded-lg border p-4 text-left transition-colors hover:border-blue-500 focus-visible:ring-2 focus-visible:ring-offset-2" style="background: var(--surface-3); border-color: {{ $selectedBin === $bin ? 'var(--brand)' : 'var(--border)' }};">
                <div class="flex items-center justify-between gap-2 mb-2"><div class="text-sm font-medium" style="color: var(--text-primary);">{{ $bin }}</div><span class="text-xs font-semibold" style="color: var(--brand);">View FIFO</span></div>
                @foreach ($skus as $sku => $qty)
                    <div class="text-xs py-1 flex justify-between" style="color: var(--text-secondary);">
                        <span>{{ $sku }}</span>
                        <span style="color: var(--text-primary);">{{ $qty }}</span>
                    </div>
                @endforeach
            </button>
        @empty
            <div class="text-center text-sm py-10 sm:col-span-2" style="color: var(--text-muted);">No bins in use yet.</div>
        @endforelse
    </div>
</div>
