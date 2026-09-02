<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Yield & Loss Analysis</div>
            <h1 class="text-2xl font-bold">Yield & Loss Analysis</h1>
        </div>

        <div class="access-grid">
            <div class="access-card p-4">
                <div class="access-muted text-sm">Average scrap % (last {{ $workOrders->count() }} work orders)</div>
                <div class="text-2xl font-bold">{{ $averageScrapPct }}%</div>
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Work Order Yield</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>WO</th><th>Product</th><th>Target</th><th>Good</th><th>Rework</th><th>Rejected</th><th>Scrap %</th><th>Yield %</th></tr></thead>
                    <tbody>
                        @forelse ($workOrders as $row)
                            <tr>
                                <td>{{ $row['wo']->wo_number }}</td>
                                <td>{{ $row['wo']->finishedGood?->name }}</td>
                                <td>{{ $row['wo']->target_qty }} {{ $row['wo']->uom }}</td>
                                <td>{{ $row['wo']->good_qty }}</td>
                                <td>{{ $row['wo']->rework_qty }}</td>
                                <td>{{ $row['wo']->rejected_qty }}</td>
                                <td>
                                    <span class="access-chip" @if($row['scrap_pct'] > 5) style="background:#fee2e2;color:#b91c1c" @endif>{{ $row['scrap_pct'] }}%</span>
                                </td>
                                <td>{{ $row['yield_pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="access-muted text-center py-6">No work orders have reached reconciliation yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="access-grid">
            <div class="access-card p-5">
                <h2 class="font-bold mb-3">Worst Scrap % (Top 5)</h2>
                <div class="space-y-2">
                    @forelse ($worstOffenders as $row)
                        <div class="flex justify-between text-sm">
                            <span>{{ $row['wo']->wo_number }} — {{ $row['wo']->finishedGood?->name }}</span>
                            <strong>{{ $row['scrap_pct'] }}%</strong>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No data yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="access-card p-5">
                <h2 class="font-bold mb-3">Wastage by Reason</h2>
                <div class="space-y-2">
                    @forelse ($reasonBreakdown as $reason)
                        <div class="flex justify-between text-sm">
                            <span>{{ $reason->reason }} ({{ $reason->records }})</span>
                            <strong>{{ $reason->total_qty }}</strong>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No wastage recorded yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
