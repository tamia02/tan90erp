<?php

use App\Models\AuditLogEntry;
use App\Models\GateEntry;
use App\Models\ValidationIssue;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['GRN', 'SKU', 'Purchase Order', 'Store Manager'];

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

        return [
            'awaitingGrn' => GateEntry::where('status', 'qc_done')->count(),
            'closedToday' => GateEntry::where('status', 'closed')->whereDate('updated_at', today())->count(),
            'openIssues' => ValidationIssue::where('status', 'open')->count(),
            'recentlyClosed' => GateEntry::with('grnRecord')->where('status', 'closed')->orderByDesc('updated_at')->limit(5)->get(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Store Manager Dashboard</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">GRN posting, stock ledger and validation oversight.</p>

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Awaiting GRN</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $awaitingGrn }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Closed Today</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $closedToday }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Open Issues</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $openIssues }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Gate Entry Close</h2>
        @if ($recentlyClosed->isEmpty())
            <p class="text-sm py-2" style="color: var(--text-muted);">Nothing closed yet.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($recentlyClosed as $g)
                    <div class="py-2.5 flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · bin {{ $g->grnRecord?->suggested_bin }}</div>
                        </div>
                        <span class="text-xs font-medium" style="color: var(--status-good);">Closed · stock updated</span>
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
