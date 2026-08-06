<?php

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\QcResult;
use App\Models\VendorMaster;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// Reads existing GateEntry/QcResult/FinanceRecord data - no new tables.
// Vendor identity is the same free-text vendor_name string already used
// everywhere else in this app (gate entry, finance record, vendor login).
new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $vendorNames = GateEntry::whereNotNull('vendor_name')->distinct()->pluck('vendor_name');

        $scorecards = $vendorNames->map(function (string $vendorName) {
            $entries = GateEntry::where('vendor_name', $vendorName)->get();
            $closed = $entries->where('status', 'closed');
            $onTime = $closed->filter(fn ($e) => ! $e->sla_deadline || $e->updated_at->lte($e->sla_deadline));

            $qcResults = QcResult::whereIn('gate_entry_id', $entries->pluck('id'))->get();
            $invoiceQty = (float) $qcResults->sum('invoice_qty');
            $acceptedQty = (float) $qcResults->sum('accepted_qty');
            $rejectedQty = (float) $qcResults->sum('rejected_qty');
            $defectiveQty = (float) $qcResults->sum('defective_qty');

            $financeRecords = FinanceRecord::where('vendor_name', $vendorName)->get();

            return [
                'vendor_name' => $vendorName,
                'master' => VendorMaster::where('vendor_name', $vendorName)->first(),
                'total_deliveries' => $entries->count(),
                'closed_deliveries' => $closed->count(),
                'on_time_pct' => $closed->count() > 0 ? round($onTime->count() / $closed->count() * 100) : null,
                'accept_pct' => $invoiceQty > 0 ? round($acceptedQty / $invoiceQty * 100) : null,
                'reject_pct' => $invoiceQty > 0 ? round(($rejectedQty + $defectiveQty) / $invoiceQty * 100, 1) : null,
                'total_payable' => (float) $financeRecords->sum('final_payable'),
                'open_claims' => $financeRecords->whereIn('vendor_status', ['pending', 'hold'])->count(),
            ];
        })->sortByDesc('total_deliveries')->values();

        return ['scorecards' => $scorecards];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Supplier Scorecards</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">On-time delivery, quality acceptance and finance standing per vendor, computed from actual gate/QC/finance records.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Vendor</th>
                        <th class="px-4 py-2.5 font-medium">Deliveries</th>
                        <th class="px-4 py-2.5 font-medium">On-time %</th>
                        <th class="px-4 py-2.5 font-medium">Accepted %</th>
                        <th class="px-4 py-2.5 font-medium">Rejected/Defective %</th>
                        <th class="px-4 py-2.5 font-medium">Total Payable</th>
                        <th class="px-4 py-2.5 font-medium">Open Claims</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($scorecards as $s)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">
                                {{ $s['vendor_name'] }}
                                @if ($s['master']?->category)<div class="text-xs" style="color: var(--text-muted);">{{ $s['master']->category }}</div>@endif
                            </td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $s['total_deliveries'] }}</td>
                            <td class="px-4 py-2.5" style="color: {{ $s['on_time_pct'] === null ? 'var(--text-muted)' : ($s['on_time_pct'] >= 90 ? 'var(--status-good)' : ($s['on_time_pct'] >= 70 ? 'var(--status-warning)' : 'var(--status-critical)')) }};">{{ $s['on_time_pct'] !== null ? $s['on_time_pct'].'%' : '—' }}</td>
                            <td class="px-4 py-2.5" style="color: {{ $s['accept_pct'] === null ? 'var(--text-muted)' : ($s['accept_pct'] >= 95 ? 'var(--status-good)' : ($s['accept_pct'] >= 85 ? 'var(--status-warning)' : 'var(--status-critical)')) }};">{{ $s['accept_pct'] !== null ? $s['accept_pct'].'%' : '—' }}</td>
                            <td class="px-4 py-2.5" style="color: {{ $s['reject_pct'] === null ? 'var(--text-muted)' : ($s['reject_pct'] <= 2 ? 'var(--status-good)' : ($s['reject_pct'] <= 8 ? 'var(--status-warning)' : 'var(--status-critical)')) }};">{{ $s['reject_pct'] !== null ? $s['reject_pct'].'%' : '—' }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-primary);">₹{{ number_format($s['total_payable'], 0) }}</td>
                            <td class="px-4 py-2.5" style="color: {{ $s['open_claims'] > 0 ? 'var(--status-warning)' : 'var(--text-muted)' }};">{{ $s['open_claims'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No vendor activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
