<?php

use App\Models\LedgerEntry;
use App\Support\RoleCapability;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $search = '';
    public ?string $selectedSku = null;
    public ?string $selectedBin = null;
    public bool $showHoldBreakdown = false;
    public array $columns = ['sku', 'bin', 'purchase_order', 'available', 'unit_price', 'available_value', 'stock_hold', 'last_updated'];

    private array $columnLabels = [
        'sku' => 'SKU / Item', 'bin' => 'Warehouse / Bin', 'purchase_order' => 'PO Number', 'available' => 'Available',
        'stock_hold' => 'Stock Hold', 'last_updated' => 'Last Updated',
        'defective' => 'Defective', 'rejected' => 'Rejected', 'qc_hold' => 'QC Hold',
        'total' => 'Total Stock', 'unit_price' => 'Unit Price', 'available_value' => 'Available Value',
    ];

    public function updatedColumns(): void
    {
        $this->columns = array_values(array_intersect(array_keys($this->columnLabels), $this->columns));
        if (! $this->columns) $this->columns = ['sku', 'bin', 'purchase_order', 'available', 'unit_price', 'available_value', 'stock_hold', 'last_updated'];
    }

    public function openStock(string $sku, string $bin): void
    {
        $this->selectedSku = $sku;
        $this->selectedBin = $bin;
        $this->showHoldBreakdown = false;
    }

    public function openHold(string $sku, string $bin): void
    {
        $this->openStock($sku, $bin);
        $this->showHoldBreakdown = true;
    }

    public function closeStock(): void
    {
        $this->reset(['selectedSku', 'selectedBin', 'showHoldBreakdown']);
    }

    public function toggleHoldBreakdown(): void
    {
        $this->showHoldBreakdown = ! $this->showHoldBreakdown;
    }

    private function balanceRows()
    {
        return LedgerEntry::query()->with(['gateEntry.purchaseOrder.lines', 'gateEntry.grnRecord.lines'])->latest('created_at')->limit(5000)->get()
            ->groupBy(fn ($e) => $e->sku.'|'.$e->bin)
            ->map(function ($entries, $key) {
                $first = $entries->first();
                $qty = fn (array $buckets) => (int) $entries->filter(fn ($e) => in_array(Str::lower(preg_replace('/[\s_-]/', '', (string) $e->bucket)), $buckets, true))->sum('qty');
                $available = $qty(['available']);
                $qcHold = $qty(['qchold', 'onhold', 'hold']);
                $defective = $qty(['defective', 'defect']);
                $rejected = $qty(['rejected', 'reject']);
                $availableEntries = $entries->filter(fn ($e) => Str::lower((string) $e->bucket) === 'available');
                $unitRate = function ($entry): ?float {
                    if ($entry->unit_price !== null) return (float) $entry->unit_price;
                    $gate = $entry->gateEntry;
                    $grnLine = $gate?->grnRecord?->lines?->first(fn ($line) => mb_strtolower(trim((string) $line->sku)) === mb_strtolower(trim((string) $entry->sku)));
                    if ($grnLine?->unit_price !== null) return (float) $grnLine->unit_price;
                    $poLine = $gate?->purchaseOrder?->lines?->first(fn ($line) => mb_strtolower(trim((string) $line->product)) === mb_strtolower(trim((string) $entry->sku)));
                    return $poLine?->list_price !== null ? (float) $poLine->list_price : null;
                };
                $availableValue = round((float) $availableEntries->sum(function ($entry) use ($unitRate): float {
                    return (float) ($entry->extended_value ?? ((int) $entry->qty * (float) ($unitRate($entry) ?? 0)));
                }), 2);

                return [
                    'key' => $key, 'sku' => (string) $first->sku, 'bin' => (string) $first->bin,
                    'available' => $available, 'qc_hold' => $qcHold, 'defective' => $defective,
                    'rejected' => $rejected, 'stock_hold' => $qcHold + $defective + $rejected,
                    'total' => $available + $qcHold + $defective + $rejected,
                    'purchase_order' => $entries->first()?->gateEntry?->po_number,
                    'available_value' => $availableValue,
                    'unit_price' => $available > 0 ? round($availableValue / $available, 4) : null,
                    'last_updated' => $entries->max('updated_at'),
                ];
            })
            ->filter(fn ($row) => $row['total'] !== 0)
            ->filter(fn ($row) => trim($this->search) === '' || Str::contains(Str::lower($row['sku'].' '.$row['bin']), Str::lower(trim($this->search))))
            ->sortBy('sku')->values();
    }

    public function exportCsv()
    {
        RoleCapability::requireExport('stock_ledger');
        $rows = $this->balanceRows();
        $cols = array_values(array_intersect(array_keys($this->columnLabels), $this->columns));
        $labels = $this->columnLabels;
        return response()->streamDownload(function () use ($rows, $cols, $labels): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => $labels[$c], $cols));
            foreach ($rows as $row) fputcsv($out, array_map(fn ($c) => $c === 'last_updated' ? $row[$c]?->format('Y-m-d H:i:s') : $row[$c], $cols));
            fclose($out);
        }, 'stock-balance-'.now()->format('Ymd-His').'.csv');
    }

    public function exportExcel()
    {
        RoleCapability::requireExport('stock_ledger');
        $rows = $this->balanceRows();
        $columns = array_values(array_intersect(array_keys($this->columnLabels), $this->columns));
        return response()->streamDownload(fn () => print(view('reports.stock-balance-excel', ['rows' => $rows, 'columns' => $columns, 'labels' => $this->columnLabels])->render()), 'stock-balance-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function with(): array
    {
        $rows = $this->balanceRows();
        $movements = collect();
        if ($this->selectedSku !== null && $this->selectedBin !== null) {
            $movements = LedgerEntry::with(['gateEntry.purchaseOrder.lines', 'gateEntry.grnRecord.lines'])
                ->where('sku', $this->selectedSku)->where('bin', $this->selectedBin)
                ->latest('created_at')->get();
        }
        return ['rows' => $rows, 'movements' => $movements, 'columnLabels' => $this->columnLabels];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background:var(--surface-3);border-color:var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color:var(--text-primary);">Stock Balance</h1>
        <p class="text-sm mt-1" style="color:var(--text-secondary);">Available and total Stock Hold by SKU and warehouse/bin. Select a row or PO number for movement history; open Stock Hold to see its quality split.</p>
    </section>

    @if (session('success'))<div role="status" class="rounded-lg border p-3 mb-4 text-sm" style="border-color:var(--status-good);color:var(--status-good);">{{ session('success') }}</div>@endif
    <div class="flex flex-wrap gap-2 items-center mb-4">
        <input wire:model.live.debounce.250ms="search" aria-label="Search stock balance" placeholder="Search SKU or warehouse / bin" class="min-h-11 flex-1 min-w-60 rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);background:var(--surface-1);">
        <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv,exportExcel" aria-label="Export stock balance as CSV" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold disabled:cursor-wait disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color:var(--border);">
            <span wire:loading.remove wire:target="exportCsv">Export CSV</span><span wire:loading wire:target="exportCsv" role="status">Preparing CSV…</span>
        </button>
        <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportCsv,exportExcel" aria-label="Download stock balance as Excel" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold disabled:cursor-wait disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="border-color:var(--border);color:var(--brand);">
            <span wire:loading.remove wire:target="exportExcel">Download Excel</span><span wire:loading wire:target="exportExcel" role="status">Preparing Excel…</span>
        </button>
    </div>
    <details class="rounded-lg border p-3 mb-4" style="border-color:var(--border);background:var(--surface-3);">
        <summary class="cursor-pointer text-sm font-semibold" style="color:var(--text-primary);">Choose report columns</summary>
        <p class="text-xs my-2" style="color:var(--text-secondary);">Selections update the table and both exports. Stock Hold breakdown fields are available only in the detailed view.</p>
        <div class="flex flex-wrap gap-x-4 gap-y-2">@foreach ($columnLabels as $key => $label)<label class="inline-flex gap-2 items-center text-xs"><input type="checkbox" wire:model.live="columns" value="{{ $key }}" @disabled(in_array($key, ['defective','rejected','qc_hold'], true))>{{ $label }}</label>@endforeach</div>
    </details>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4" aria-label="Stock balance summary">
        <div class="rounded-xl border p-4" style="background:var(--surface-3);border-color:var(--border);"><div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Total Available Stock</div><div class="text-2xl font-bold mt-1" style="color:var(--status-good);">{{ number_format($rows->sum('available')) }}</div><div class="text-xs mt-1" style="color:var(--text-secondary);">Across visible SKU / warehouse rows</div></div>
        <div class="rounded-xl border p-4" style="background:var(--surface-3);border-color:var(--border);"><div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Total Stock Hold</div><div class="text-2xl font-bold mt-1" style="color:var(--status-warning);">{{ number_format($rows->sum('stock_hold')) }}</div><div class="text-xs mt-1" style="color:var(--text-secondary);">Combined hold across visible SKU / warehouse rows</div></div>
    </div>

    <div class="rounded-lg border overflow-x-auto" style="background:var(--surface-3);border-color:var(--border);">
        <table class="w-full min-w-[760px] text-sm"><thead><tr class="text-left text-xs" style="color:var(--text-muted);border-bottom:1px solid var(--border);">
            @foreach ($columns as $column)<th class="px-4 py-3 font-semibold">{{ $columnLabels[$column] }}</th>@endforeach
            <th class="px-4 py-3">Detail</th>
        </tr></thead><tbody>
            @forelse ($rows as $row)
                <tr wire:key="stock-balance-{{ md5($row['key']) }}" style="border-top:1px solid var(--border);">
                    @foreach ($columns as $column)
                        <td class="px-4 py-3 {{ $column === 'available_value' ? 'text-right' : '' }}">
                            @if ($column === 'stock_hold')<button type="button" wire:click="openHold(@js($row['sku']), @js($row['bin']))" class="underline underline-offset-2 font-semibold" style="color:{{ $row[$column] > 0 ? 'var(--status-warning)' : 'var(--text-secondary)' }};">{{ $row[$column] }}</button>
                            @elseif($column === 'purchase_order')
                                @if ($row[$column])
                                    <a href="{{ route('admin.po', ['po' => $row[$column]]) }}" wire:navigate class="underline underline-offset-2 font-semibold cursor-pointer hover:opacity-75 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="color:var(--brand);" aria-label="Open purchase order {{ $row[$column] }}">{{ $row[$column] }}</a>
                                @else
                                    —
                                @endif
                            @elseif ($column === 'last_updated'){{ $row[$column]?->format('d M Y, H:i') ?? '—' }}
                            @elseif ($column === 'unit_price'){{ $row[$column] !== null ? '₹'.number_format($row[$column], 4) : '—' }}
                            @elseif ($column === 'available_value')₹{{ number_format($row[$column], 2) }}
                            @else{{ $row[$column] }}@endif
                        </td>
                    @endforeach
                    <td class="px-4 py-3"><a href="{{ route('grn.ledger-history', ['sku' => $row['sku'], 'bin' => $row['bin']]) }}" wire:navigate class="font-semibold underline underline-offset-2 cursor-pointer hover:opacity-75 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="color:var(--brand);">View details</a></td>
                </tr>
            @empty<tr><td colspan="{{ count($columns)+1 }}" class="px-4 py-10 text-center" style="color:var(--text-muted);">No stock balance matches this search.</td></tr>@endforelse
        </tbody></table>
    </div>

    @if ($selectedSku !== null && $selectedBin !== null)
        <section class="rounded-xl border p-4 mt-5" style="background:var(--surface-3);border-color:var(--brand);">
            <div class="flex justify-between gap-3 flex-wrap items-start"><div><p class="text-xs uppercase font-semibold" style="color:var(--brand);">SKU / Bin movement history</p><h2 class="text-lg font-bold" style="color:var(--text-primary);">{{ $selectedSku }} · {{ $selectedBin }}</h2></div><button wire:click="closeStock" class="min-h-10 rounded-lg border px-3" style="border-color:var(--border);">Close</button></div>
            <div class="flex gap-2 flex-wrap mt-3">
                <button wire:click="toggleHoldBreakdown" class="min-h-10 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--status-warning);color:var(--status-warning);">{{ $showHoldBreakdown ? 'Hide' : 'Show' }} Stock Hold breakdown</button>
                @if ($showHoldBreakdown)
                    @php $holdMovements = $movements->filter(fn ($m) => in_array(strtolower(preg_replace('/[\s_-]/', '', $m->bucket)), ['qchold','onhold','hold','defective','defect','rejected','reject'], true)); @endphp
                    <span class="inline-flex items-center rounded-lg px-3 py-2 text-sm" style="background:var(--surface-2);">QC Hold: {{ $holdMovements->filter(fn ($m) => in_array(strtolower(preg_replace('/[\s_-]/', '', $m->bucket)), ['qchold','onhold','hold'], true))->sum('qty') }}</span>
                    <span class="inline-flex items-center rounded-lg px-3 py-2 text-sm" style="background:var(--surface-2);">Defective: {{ $holdMovements->filter(fn ($m) => in_array(strtolower(preg_replace('/[\s_-]/', '', $m->bucket)), ['defective','defect'], true))->sum('qty') }}</span>
                    <span class="inline-flex items-center rounded-lg px-3 py-2 text-sm" style="background:var(--surface-2);">Rejected: {{ $holdMovements->filter(fn ($m) => in_array(strtolower(preg_replace('/[\s_-]/', '', $m->bucket)), ['rejected','reject'], true))->sum('qty') }}</span>
                @endif
            </div>
            <div class="overflow-x-auto mt-3"><table class="min-w-[900px] w-full text-sm"><thead><tr class="text-left text-xs" style="color:var(--text-muted);"><th class="p-2">Timestamp</th><th class="p-2">Reference / PO</th><th class="p-2">GRN</th><th class="p-2">Bucket</th><th class="p-2">Updated Qty</th><th class="p-2">Unit Price</th><th class="p-2">Available after movement</th></tr></thead><tbody>
                @php $running = (int) $movements->sum(fn ($m) => strtolower((string) $m->bucket) === 'available' ? (int) $m->qty : 0); @endphp
                @forelse ($movements as $movement)
                    @php
                        $movementGrnLine = $movement->gateEntry?->grnRecord?->lines?->first(fn ($line) => mb_strtolower(trim((string) $line->sku)) === mb_strtolower(trim((string) $movement->sku)));
                        $movementPoLine = $movement->gateEntry?->purchaseOrder?->lines?->first(fn ($line) => mb_strtolower(trim((string) $line->product)) === mb_strtolower(trim((string) $movement->sku)));
                        $movementUnitPrice = $movement->unit_price ?? $movementGrnLine?->unit_price ?? $movementPoLine?->list_price;
                    @endphp
                    <tr style="border-top:1px solid var(--border);"><td class="p-2">{{ $movement->created_at?->format('d M Y, H:i:s') }}</td><td class="p-2">{{ $movement->gateEntry?->po_number ?: $movement->gateEntry?->gate_no ?: '—' }}</td><td class="p-2">@if($movement->gateEntry?->grnRecord?->posted)<a class="underline" style="color:var(--brand);" href="{{ route('grn.export.pdf', $movement->gateEntry) }}" target="_blank" rel="noopener">{{ $movement->gateEntry?->gate_no }} · Full GRN report</a>@else{{ $movement->gateEntry?->gate_no ?: '—' }}@endif</td><td class="p-2">{{ $movement->bucket }}</td><td class="p-2" style="color:{{ $movement->qty < 0 ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $movement->qty > 0 ? '+' : '' }}{{ $movement->qty }}</td><td class="p-2">{{ $movementUnitPrice !== null ? '₹'.number_format((float)$movementUnitPrice,4) : '—' }}</td><td class="p-2">{{ strtolower((string)$movement->bucket) === 'available' ? $running : '—' }}</td></tr>
                    @php if (strtolower((string)$movement->bucket) === 'available') $running -= (int)$movement->qty; @endphp
                @empty<tr><td colspan="7" class="p-6 text-center" style="color:var(--text-muted);">No movements recorded for this SKU/bin.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif
</div>
