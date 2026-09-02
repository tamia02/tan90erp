<?php

use App\Models\FinanceRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $records = FinanceRecord::where('vendor_status', '!=', 'cleared')->get();

        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];

        $byVendor = $records
            ->groupBy('vendor_name')
            ->map(function ($vendorRecords) use ($buckets) {
                $row = ['vendor_name' => $vendorRecords->first()->vendor_name, 'total' => 0] + $buckets;

                foreach ($vendorRecords as $record) {
                    $age = (int) $record->created_at->diffInDays(now());
                    $bucket = match (true) {
                        $age <= 30 => '0-30',
                        $age <= 60 => '31-60',
                        $age <= 90 => '61-90',
                        default => '90+',
                    };
                    $row[$bucket] += (float) $record->final_payable;
                    $row['total'] += (float) $record->final_payable;
                }

                return $row;
            })
            ->sortByDesc('total')
            ->values();

        $totals = ['total' => $byVendor->sum('total')] + collect($buckets)->keys()
            ->mapWithKeys(fn ($bucket) => [$bucket => $byVendor->sum($bucket)])
            ->all();

        return ['byVendor' => $byVendor, 'totals' => $totals];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">AP Aging Report</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Outstanding payables by vendor, bucketed by days since the GRN was posted.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">0-30 days</th>
                        <th class="px-4 py-2.5 font-medium">31-60 days</th>
                        <th class="px-4 py-2.5 font-medium">61-90 days</th>
                        <th class="px-4 py-2.5 font-medium">90+ days</th>
                        <th class="px-4 py-2.5 font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byVendor as $row)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $row['vendor_name'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">₹{{ number_format($row['0-30'], 2) }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">₹{{ number_format($row['31-60'], 2) }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-warning);">₹{{ number_format($row['61-90'], 2) }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-critical);">₹{{ number_format($row['90+'], 2) }}</td>
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">₹{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No outstanding payables.</td></tr>
                    @endforelse
                </tbody>
                @if ($byVendor->isNotEmpty())
                    <tfoot>
                        <tr style="border-top: 2px solid var(--border);">
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">Total</td>
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">₹{{ number_format($totals['0-30'], 2) }}</td>
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">₹{{ number_format($totals['31-60'], 2) }}</td>
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">₹{{ number_format($totals['61-90'], 2) }}</td>
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">₹{{ number_format($totals['90+'], 2) }}</td>
                            <td class="px-4 py-2.5 font-semibold" style="color: var(--text-primary);">₹{{ number_format($totals['total'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
