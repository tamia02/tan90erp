<?php

use App\Models\GateEntry;
use App\Support\CombinedActivityFeed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['Gate entry', 'Security Guard'];

    public bool $showAllActivity = false;

    public function with(): array
    {
        $today = GateEntry::whereDate('created_at', today())->get();

        $activity = CombinedActivityFeed::forUser(auth()->user(), self::ACTIVITY_KEYWORDS);

        return [
            'todayCount' => $today->count(),
            'pendingCount' => GateEntry::where('status', 'pending_validation')->count(),
            'breachedCount' => GateEntry::where('status', '!=', 'closed')->where('sla_deadline', '<', now())->count(),
            'recent' => GateEntry::orderByDesc('created_at')->limit(5)->get(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Guard Dashboard</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Today's gate activity at a glance.</p>

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Today's Entries</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $todayCount }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Pending Validation</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $pendingCount }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">SLA Breached</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-critical);">{{ $breachedCount }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Recent entries</h2>
        <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @forelse ($recent as $entry)
                <a href="{{ route('guard.entries.show', $entry) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $entry->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $entry->vendor_name ?? $entry->vehicle_number }}</div>
                    </div>
                    <span class="text-xs capitalize shrink-0" style="color: var(--text-muted);">{{ str_replace('_', ' ', $entry->status) }}</span>
                </a>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No gate entries yet.</p>
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
