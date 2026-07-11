<?php

use App\Models\AuditLogEntry;
use App\Models\GateEntry;
use App\Models\QcResult;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['QC', 'QC User'];

    public bool $showAllActivity = false;

    public function with(): array
    {
        $activity = AuditLogEntry::orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn ($row) => collect(self::ACTIVITY_KEYWORDS)->contains(
                fn ($keyword) => str_contains($row->action, $keyword) || str_contains((string) $row->detail, $keyword)
            ))
            ->values();

        // Vendor-wise short (missing) & rejected quantity — lets QC see
        // which vendors are actually causing the shortfalls/rejections.
        $vendorBreakdown = QcResult::with('gateEntry')
            ->get()
            ->groupBy(fn ($r) => $r->gateEntry?->vendor_name ?? 'Unknown vendor')
            ->map(fn ($rows, $vendor) => [
                'vendor' => $vendor,
                'short_qty' => $rows->sum('missing_qty'),
                'rejected_qty' => $rows->sum('rejected_qty'),
            ])
            ->filter(fn ($row) => $row['short_qty'] > 0 || $row['rejected_qty'] > 0)
            ->sortByDesc(fn ($row) => $row['short_qty'] + $row['rejected_qty'])
            ->values();

        return [
            'inQueue' => GateEntry::where('status', 'grn')->count(),
            'doneToday' => GateEntry::whereIn('status', ['qc_done', 'rejected'])->whereDate('updated_at', today())->count(),
            'rejectedToday' => GateEntry::where('status', 'rejected')->whereDate('updated_at', today())->count(),
            'queue' => GateEntry::where('status', 'grn')->orderBy('created_at')->limit(5)->get(),
            'completedCount' => QcResult::count(),
            'acceptedQty' => QcResult::sum('accepted_qty'),
            'holdQty' => QcResult::sum('qc_hold_qty'),
            'defectiveQty' => QcResult::sum('defective_qty'),
            'rejectedQty' => QcResult::sum('rejected_qty'),
            'missingQty' => QcResult::sum('missing_qty'),
            'vendorBreakdown' => $vendorBreakdown,
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">QC Dashboard</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Unloaded deliveries waiting for the accept/hold/defective/reject split.</p>

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">In QC Queue</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $inQueue }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Completed Today</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $doneToday }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Fully Rejected Today</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-critical);">{{ $rejectedToday }}</div>
        </div>
    </div>

    <h2 class="font-semibold text-sm mb-2" style="color: var(--text-primary);">Status counters</h2>
    <div class="grid grid-cols-3 sm:grid-cols-6 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Completed</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $completedCount }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Accepted</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-good);">{{ $acceptedQty }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Hold</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-warning);">{{ $holdQty }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Defective</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-critical);">{{ $defectiveQty }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Rejected</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-critical);">{{ $rejectedQty }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Missing</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-warning);">{{ $missingQty }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Short &amp; Rejected quantity by vendor</h2>
        @if ($vendorBreakdown->isEmpty())
            <p class="text-sm py-2" style="color: var(--text-muted);">No short or rejected quantity recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                            <th class="py-2 font-medium">Vendor</th>
                            <th class="py-2 font-medium">Short Qty</th>
                            <th class="py-2 font-medium">Rejected Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vendorBreakdown as $row)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="py-2 font-medium" style="color: var(--text-primary);">{{ $row['vendor'] }}</td>
                                <td class="py-2" style="color: var(--status-warning);">{{ $row['short_qty'] }}</td>
                                <td class="py-2" style="color: var(--status-critical);">{{ $row['rejected_qty'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">QC queue</h2>
        @if ($queue->isEmpty())
            <p class="text-sm py-4" style="color: var(--text-muted);">Nothing waiting.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($queue as $g)
                    <div class="py-3 flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->material }} · {{ $g->invoice_qty }} qty</div>
                        </div>
                        <a href="{{ route('qc.queue') }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">Check →</a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-sm" style="color: var(--text-primary);">Activity</h2>
            @if ($activityTotal > 5)
                <button wire:click="$toggle('showAllActivity')" class="text-xs font-medium" style="color: var(--brand);">
                    {{ $showAllActivity ? 'Show less' : 'View all activity ('.$activityTotal.')' }}
                </button>
            @endif
        </div>
        <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @forelse ($recentActivity as $row)
                <a href="{{ route('activity.detail', $row) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
                </a>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
