<?php

use App\Models\LedgerEntry;
use Illuminate\Support\Str;
use App\Support\RoleCapability;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $search = '';

    public function with(): array
    {
        return ['entries' => $this->filteredEntries()];
    }

    public function exportCsv()
    {
        RoleCapability::requireExport('stock_ledger');
        $entries = $this->filteredEntries();

        return response()->streamDownload(function () use ($entries): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['SKU Code', 'Item Name', 'Available', 'On Hold', 'Rejected', 'Defective', 'Total', 'Last Updated']);

            foreach ($this->summaryRows($entries) as $row) {
                fputcsv($handle, [
                    $row['sku_code'], $row['item_name'], $row['available'], $row['on_hold'],
                    $row['rejected'], $row['defective'], $row['total'], $row['last_updated'],
                ]);
            }

            fclose($handle);
        }, 'stock-ledger-'.now()->format('Ymd-His').'.csv');
    }

    public function exportUpdatesCsv()
    {
        RoleCapability::requireExport('stock_updates');
        $entries = $this->filteredEntries();

        return response()->streamDownload(function () use ($entries): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Updated At', 'Gate No', 'PO Number', 'Entry Type', 'SKU Code', 'Bin', 'Bucket', 'Direction', 'Quantity', 'Unit Price', 'Movement Value']);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->created_at?->format('Y-m-d H:i:s'),
                    $entry->gateEntry?->gate_no,
                    $entry->gateEntry?->po_number,
                    $entry->gateEntry?->entry_type,
                    $entry->sku,
                    $entry->bin,
                    $entry->bucket,
                    (int) $entry->qty >= 0 ? 'In' : 'Out',
                    abs((int) $entry->qty),
                    $entry->unit_price,
                    $entry->extended_value,
                ]);
            }

            fclose($handle);
        }, 'inventory-updates-'.now()->format('Ymd-His').'.csv');
    }

    private function filteredEntries()
    {
        return LedgerEntry::query()
            ->with('gateEntry')
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($nested) use ($term): void {
                    $nested->where('sku', 'like', $term)
                        ->orWhere('bin', 'like', $term)
                        ->orWhere('bucket', 'like', $term)
                        ->orWhereHas('gateEntry', function ($gateQuery) use ($term): void {
                            $gateQuery->where('gate_no', 'like', $term)
                                ->orWhere('po_number', 'like', $term);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
    }

    private function summaryRows($entries): array
    {
        return $entries->groupBy('sku')->map(function ($lines, $sku): array {
            $row = [
                'sku_code' => $sku,
                'item_name' => $sku,
                'available' => 0,
                'on_hold' => 0,
                'rejected' => 0,
                'defective' => 0,
                'total' => 0,
                'last_updated' => $lines->max('updated_at')?->format('d M Y, H:i'),
            ];

            foreach ($lines as $line) {
                $bucket = Str::lower((string) $line->bucket);
                $bucket = str_replace([' ', '-', '_'], '', $bucket);
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

            return $row;
        })->values()->all();
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Stock Ledger</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">One row per SKU, with available, hold, rejected and defective quantities aggregated across all postings.</p>
    </section>

    @include('partials.stock-ledger-table')
</div>
