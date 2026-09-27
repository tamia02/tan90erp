<?php

use App\Models\GateEntry;
use App\Support\CombinedActivityFeed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // bill-scan.blade.php's saveEntry() logs "{Inward|Outward|Visitor} entry
    // created" — 'Gate entry'/'Security Guard' never appeared in that string,
    // so this feed was silently empty for every guard, always.
    private const ACTIVITY_KEYWORDS = ['entry created'];

    public bool $showAllActivity = false;

    public function with(): array
    {
        $activity = CombinedActivityFeed::forUser(auth()->user(), self::ACTIVITY_KEYWORDS);

        return [
            // Keep the dashboard cheap even on a busy gate day. The previous
            // implementation hydrated every today's row just to count it and
            // then sorted that collection to display five entries.
            'todayCount' => GateEntry::whereDate('created_at', today())->count(),
            'pendingCount' => GateEntry::where('status', 'pending_validation')->count(),
            'breachedCount' => GateEntry::where('status', '!=', 'closed')->where('sla_deadline', '<', now())->count(),
            'visitorsOnSite' => GateEntry::where('entry_type', 'visitor')->where('status', 'checked_in')->count(),
            // Scoped to today, not just "most recent 5 ever".
            'recent' => GateEntry::whereDate('created_at', today())->latest('created_at')->limit(5)->get(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Guard Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Dashboard</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Today's gate activity at a glance.</p>
        <div class="flex flex-wrap gap-2 mt-4">
            <a href="{{ route('guard.scan') }}" wire:navigate class="rounded-lg px-3.5 py-2 text-sm font-semibold text-white" style="background: var(--brand);">Create gate entry</a>
            <a href="{{ route('guard.entries', ['type' => 'inward']) }}" wire:navigate class="rounded-lg px-3.5 py-2 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">Inward log</a>
            <a href="{{ route('guard.entries', ['type' => 'outward']) }}" wire:navigate class="rounded-lg px-3.5 py-2 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">Outward log</a>
            <a href="{{ route('guard.entries', ['type' => 'visitor']) }}" wire:navigate class="rounded-lg px-3.5 py-2 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">Visitor log</a>
        </div>
    </section>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <a href="{{ route('guard.entries', ['today' => 1]) }}" wire:navigate class="rounded-2xl border p-4 flex items-center gap-3.5 transition-colors hover:border-[var(--brand)]" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--brand-bg); color: var(--brand);">
                <x-icon name="clipboard-list" class="w-6 h-6" />
            </div>
            <div class="min-w-0">
                <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $todayCount }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Today's Entries</div>
            </div>
        </a>
        <a href="{{ route('guard.entries', ['status' => 'pending_validation']) }}" wire:navigate class="rounded-2xl border p-4 flex items-center gap-3.5 transition-colors hover:border-[var(--status-warning)]" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--status-warning-bg); color: var(--status-warning);">
                <x-icon name="shield-alert" class="w-6 h-6" />
            </div>
            <div class="min-w-0">
                <div class="text-2xl font-bold" style="color: var(--status-warning);">{{ $pendingCount }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Pending Validation</div>
            </div>
        </a>
        <a href="{{ route('guard.entries', ['breached' => 1]) }}" wire:navigate class="rounded-2xl border p-4 flex items-center gap-3.5 transition-colors hover:border-[var(--status-critical)]" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--status-critical-bg); color: var(--status-critical);">
                <x-icon name="history" class="w-6 h-6" />
            </div>
            <div class="min-w-0">
                <div class="text-2xl font-bold" style="color: var(--status-critical);">{{ $breachedCount }}</div>
                <div class="text-xs" style="color: var(--text-muted);">SLA Breached</div>
            </div>
        </a>
        <a href="{{ route('guard.entries', ['type' => 'visitor']) }}" wire:navigate class="rounded-2xl border p-4 flex items-center gap-3.5 transition-colors hover:border-[var(--status-good)]" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--status-good-bg); color: var(--status-good);">
                <x-icon name="users" class="w-6 h-6" />
            </div>
            <div class="min-w-0">
                <div class="text-2xl font-bold" style="color: var(--status-good);">{{ $visitorsOnSite }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Visitors on site · view log</div>
            </div>
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Recent entries</h2>
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @forelse ($recent as $entry)
                    <a href="{{ route('guard.entries.show', $entry) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                        <div class="min-w-0">
                            <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $entry->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $entry->vendor_name ?? $entry->vehicle_number }}</div>
                        </div>
                        <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ \App\Support\GateStatusLabels::label($entry->status) }}</span>
                    </a>
                @empty
                    <p class="text-sm py-4" style="color: var(--text-muted);">No gate entries yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
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
        </section>
    </div>
</div>
