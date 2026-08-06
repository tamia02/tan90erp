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

    public function transfer(): void
    {
        $this->transferError = null;
        $this->validate([
            'fromBin' => ['required', 'string'],
            'toBin' => ['required', 'string', 'different:fromBin'],
            'sku' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $available = (int) LedgerEntry::where('bin', $this->fromBin)->where('sku', $this->sku)->where('bucket', 'available')->sum('qty');
        $qty = (int) $this->qty;

        if ($qty > $available) {
            $this->transferError = "Only {$available} available for {$this->sku} in {$this->fromBin}.";

            return;
        }

        // Bin-to-bin putaway doesn't create new stock - it just relocates
        // existing received stock, so it reuses the gate entry that most
        // recently put this SKU into the source bin (ledger_entries.
        // gate_entry_id is required, not nullable).
        $gateEntryId = LedgerEntry::where('bin', $this->fromBin)->where('sku', $this->sku)->where('bucket', 'available')->latest()->value('gate_entry_id');

        LedgerEntry::create(['gate_entry_id' => $gateEntryId, 'sku' => $this->sku, 'bin' => $this->fromBin, 'bucket' => 'available', 'qty' => -$qty]);
        LedgerEntry::create(['gate_entry_id' => $gateEntryId, 'sku' => $this->sku, 'bin' => $this->toBin, 'bucket' => 'available', 'qty' => $qty]);

        AuditLogger::log('Bin transfer', "{$this->sku} · {$qty} · {$this->fromBin} → {$this->toBin}");

        $this->reset(['fromBin', 'toBin', 'sku', 'qty']);
    }

    public function with(): array
    {
        $byBin = LedgerEntry::where('bucket', 'available')
            ->get()
            ->groupBy('bin')
            ->map(function ($group) {
                return $group->groupBy('sku')->map(fn ($skuGroup) => $skuGroup->sum('qty'))->filter(fn ($qty) => $qty > 0);
            })
            ->filter(fn ($skus) => $skus->isNotEmpty());

        return ['byBin' => $byBin];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Shelf &amp; Bin</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">What's currently sitting in each bin, and putaway moves between bins.</p>

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

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @forelse ($byBin as $bin => $skus)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium mb-2" style="color: var(--text-primary);">{{ $bin }}</div>
                @foreach ($skus as $sku => $qty)
                    <div class="text-xs py-1 flex justify-between" style="color: var(--text-secondary);">
                        <span>{{ $sku }}</span>
                        <span style="color: var(--text-primary);">{{ $qty }}</span>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="text-center text-sm py-10 sm:col-span-2" style="color: var(--text-muted);">No bins in use yet.</div>
        @endforelse
    </div>
</div>
