<?php

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderProgressService;
use App\Models\ValidationIssue;
use App\Support\CombinedActivityFeed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['GRN', 'SKU', 'Purchase Order', 'Store Manager'];

    public bool $showAllActivity = false;

    public function with(PurchaseOrderProgressService $progress): array
    {
        $activity = CombinedActivityFeed::forUser(auth()->user(), self::ACTIVITY_KEYWORDS);
        $orders = PurchaseOrder::with('lines')->whereNotNull('released_at')->orderByDesc('updated_at')->limit(8)->get();

        return [
            'awaitingApproval' => GateEntry::whereIn('entry_type', ['inward', 'outward'])->where('status', 'pending_validation')->count(),
            'awaitingGrn' => GateEntry::where('status', 'qc_done')->count(),
            'closedToday' => GateEntry::where('status', 'closed')->whereDate('updated_at', today())->count(),
            'openIssues' => ValidationIssue::where('status', 'open')->count(),
            'recentlyClosed' => GateEntry::with('grnRecord')->where('status', 'closed')->orderByDesc('updated_at')->limit(5)->get(),
            'poProgress' => $progress->summarize($orders),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Dashboard</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">GRN posting, consolidated Stock Balance, and validation oversight.</p>
    </section>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <a href="{{ route('store-manager.entry-approvals') }}" wire:navigate class="rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Awaiting Approval</div>
            <div class="text-2xl font-semibold mt-1" style="color: {{ $awaitingApproval > 0 ? 'var(--status-warning)' : 'var(--text-primary)' }};">{{ $awaitingApproval }}</div>
        </a>
        <a href="{{ route('grn.check') }}" wire:navigate class="rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Awaiting GRN</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $awaitingGrn }}</div>
            <div class="text-xs mt-1 font-semibold" style="color: var(--brand);">Open GRN Check →</div>
        </a>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Closed Today</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $closedToday }}</div>
        </div>
        <a href="{{ route('validation.issues') }}" wire:navigate class="rounded-lg border p-4 hover:opacity-80" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Open Issues</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $openIssues }}</div>
            <div class="text-xs mt-1 font-semibold" style="color: var(--brand);">Review issues →</div>
        </a>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Gate Entry Close</h2>
        @if ($recentlyClosed->isEmpty())
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing closed yet.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($recentlyClosed as $g)
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="py-2.5 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · bin {{ $g->final_bin ?? $g->grnRecord?->suggested_bin }}</div>
                        </div>
                        <span class="text-xs font-medium shrink-0" style="color: var(--status-good);">Closed · stock updated</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
            <div>
                <h2 class="font-semibold text-sm" style="color: var(--text-primary);">PO Control Tower</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Search any PO number to see bills, gate, QC, GRN, putaway and finance in one connected flow.</p>
            </div>
            <a href="{{ route('grn.po-control-tower') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background: var(--brand);">Open control tower →</a>
        </div>
        <div class="space-y-2">
            @forelse ($poProgress as $p)
                <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $p['po']->po_number }} · {{ $p['po']->vendor_name }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $p['stage'] }} · {{ number_format($p['billed_qty'], 0) }}/{{ number_format($p['ordered_qty'], 0) }} billed · {{ $p['invoice_count'] }} bill(s)</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs font-semibold" style="color: var(--status-warning);">Next: {{ $p['next_action']['label'] }}</div>
                            @if (in_array($p['next_action']['route'], ['store-manager.entry-approvals', 'validation.issues', 'grn.check', 'admin.po'], true))
                                <a href="{{ route($p['next_action']['route']) }}" wire:navigate class="text-xs font-semibold" style="color: var(--brand);">Take action →</a>
                            @elseif ($p['next_action']['route'] === 'gate-entries.show' && $p['latest_gate'])
                                <a href="{{ route('gate-entries.show', $p['latest_gate']) }}" wire:navigate class="text-xs font-semibold" style="color: var(--brand);">View connected record →</a>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm py-3" style="color: var(--text-muted);">No released POs yet.</p>
            @endforelse
        </div>
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
                <a href="{{ $row['url'] }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row['title'] }}</div>
                        @if ($row['detail'])
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row['detail'] }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row['created_at']->format('d M, H:i') }}</span>
                </a>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
