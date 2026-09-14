<?php

use App\Models\FinanceRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $byVendor = FinanceRecord::all()
            ->groupBy('vendor_name')
            ->map(function ($records) {
                return [
                    'vendor_name' => $records->first()->vendor_name,
                    'total_payable' => $records->sum('final_payable'),
                    'pending' => $records->where('vendor_status', 'pending')->sum('final_payable'),
                    'count' => $records->count(),
                ];
            })
            ->values();

        return ['claims' => $byVendor];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Finance Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Vendor Claims</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Payables grouped by vendor.</p>
    </section>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Records</th>
                        <th class="px-4 py-2.5 font-medium">Total Payable</th>
                        <th class="px-4 py-2.5 font-medium">Pending</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($claims as $c)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $c['vendor_name'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $c['count'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-primary);">₹{{ number_format($c['total_payable'], 2) }}</td>
                            <td class="px-4 py-2.5" style="color: {{ $c['pending'] > 0 ? 'var(--status-warning)' : 'var(--status-good)' }};">₹{{ number_format($c['pending'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendor claims yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
