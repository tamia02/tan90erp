@php
    $ledgerRows = collect($entries ?? [])->groupBy('sku')->map(function ($lines, $sku) {
        $row = [
            'sku_code' => $sku,
            'item_name' => $sku,
            'available' => 0,
            'on_hold' => 0,
            'rejected' => 0,
            'defective' => 0,
            'total' => 0,
            'last_updated' => null,
        ];

        foreach ($lines as $line) {
            $bucket = strtolower(str_replace([' ', '-', '_'], '', (string) $line->bucket));
            $column = match ($bucket) {
                'available' => 'available',
                'qchold', 'onhold', 'hold' => 'on_hold',
                'rejected', 'reject' => 'rejected',
                'defective', 'defect' => 'defective',
                default => null,
            };

            if ($column) {
                $row[$column] += (int) $line->qty;
                $row['total'] += (int) $line->qty;
            }
        }

        $latest = $lines->sortByDesc('updated_at')->first()?->updated_at;
        $row['last_updated'] = $latest?->format('d M Y, H:i');

        return $row;
    })->values();
@endphp

<div data-listing-ignore class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
    @if (isset($search))
        <div class="flex flex-col sm:flex-row gap-2 p-3 border-b" style="border-color: var(--border);">
            <input wire:model.live.debounce.250ms="search" class="min-h-11 flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Search SKU, bucket or bin" aria-label="Search stock ledger" />
            <button type="button" wire:click="exportCsv" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Export CSV</button>
            <button type="button" wire:click="exportUpdatesCsv" class="min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Export updates</button>
            <span class="self-center text-xs" style="color: var(--text-muted);">{{ $ledgerRows->count() }} SKU{{ $ledgerRows->count() === 1 ? '' : 's' }}</span>
        </div>
    @endif
    <div class="px-4 py-2 text-xs" style="color: var(--text-muted); background: var(--surface-2);">Bins are intentionally omitted here: rejected, on-hold and defective quantities are dispositions, not putaway stock.</div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">SKU Code</th>
                    <th class="px-4 py-2.5 font-medium">Item Name</th>
                    <th class="px-4 py-2.5 font-medium text-right">Available</th>
                    <th class="px-4 py-2.5 font-medium text-right">On Hold</th>
                    <th class="px-4 py-2.5 font-medium text-right">Rejected</th>
                    <th class="px-4 py-2.5 font-medium text-right">Defective</th>
                    <th class="px-4 py-2.5 font-medium text-right">Total</th>
                    <th class="px-4 py-2.5 font-medium">Last Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ledgerRows as $row)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);"><a href="{{ route('grn.ledger-history', ['sku' => $row['sku_code']]) }}" wire:navigate class="hover:underline" style="color: var(--brand);">{{ $row['sku_code'] }}</a></td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $row['item_name'] }}</td>
                        <td class="px-4 py-2.5 text-right" style="color: var(--status-good);">{{ $row['available'] }}</td>
                        <td class="px-4 py-2.5 text-right" style="color: var(--status-warning);">{{ $row['on_hold'] }}</td>
                        <td class="px-4 py-2.5 text-right" style="color: var(--status-critical);">{{ $row['rejected'] }}</td>
                        <td class="px-4 py-2.5 text-right" style="color: var(--status-critical);">{{ $row['defective'] }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold" style="color: var(--text-primary);">{{ $row['total'] }}</td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-muted);">{{ $row['last_updated'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock ledger entries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <section class="border-t" style="border-color: var(--border);">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 py-3" style="background: var(--surface-2);">
            <div>
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Inventory Updates Log</h2>
                <p class="text-xs mt-0.5" style="color: var(--text-secondary);">Every stock movement posted by GRN or bin transfer, including the source gate and PO reference.</p>
            </div>
            @if (isset($search))
                <span class="text-xs" style="color: var(--text-muted);">{{ $entries->count() }} movement{{ $entries->count() === 1 ? '' : 's' }} shown</span>
            @else
                <span class="text-xs" style="color: var(--text-muted);">Latest movements</span>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Updated</th>
                        <th class="px-4 py-2.5 font-medium">Gate</th>
                        <th class="px-4 py-2.5 font-medium">PO</th>
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Bucket</th>
                        <th class="px-4 py-2.5 font-medium text-right">Change</th>
                        <th class="px-4 py-2.5 font-medium text-right">Unit price</th>
                        <th class="px-4 py-2.5 font-medium text-right">Movement value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        @php $isInbound = (int) $entry->qty >= 0; @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color: var(--text-muted);">{{ $entry->created_at?->format('d M Y, H:i') ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color: var(--text-primary);">{{ $entry->gateEntry?->gate_no ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $entry->gateEntry?->po_number ?: '—' }}</td>
                            <td class="px-4 py-2.5 font-medium whitespace-nowrap" style="color: var(--text-primary);">{{ $entry->sku }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $entry->bin ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-xs whitespace-nowrap" style="color: {{ in_array(strtolower(str_replace([' ', '-', '_'], '', (string) $entry->bucket)), ['rejected', 'defective'], true) ? 'var(--status-critical)' : (in_array(strtolower(str_replace([' ', '-', '_'], '', (string) $entry->bucket)), ['qchold', 'onhold', 'hold'], true) ? 'var(--status-warning)' : 'var(--status-good)') }};">{{ $entry->bucket }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold whitespace-nowrap" style="color: {{ $isInbound ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $isInbound ? '+' : '−' }}{{ abs((int) $entry->qty) }} <span class="text-xs font-normal">{{ $isInbound ? 'In' : 'Out' }}</span></td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">{{ $entry->unit_price !== null ? '₹'.number_format((float) $entry->unit_price, 4) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">{{ $entry->extended_value !== null ? '₹'.number_format((float) $entry->extended_value, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">No inventory updates found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
