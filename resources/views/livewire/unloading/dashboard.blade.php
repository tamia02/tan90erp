<?php

use App\Models\GateEntry;
use App\Support\CombinedActivityFeed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['Unloading', 'Store Executive'];

    public bool $showAllActivity = false;

    public function with(): array
    {
        $activity = CombinedActivityFeed::forUser(auth()->user(), self::ACTIVITY_KEYWORDS);

        return [
            'awaiting' => GateEntry::where('status', 'validated')->where('entry_type', 'inward')->count(),
            'onDock' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'inward')->count(),
            'allotted' => GateEntry::where('status', 'allotted')->where('entry_type', 'inward')->count(),
            'inProgress' => GateEntry::where('status', 'unloading')->where('entry_type', 'inward')->count(),
            'readyForQc' => GateEntry::where('status', 'grn')->where('entry_type', 'inward')->count(),
            'queue' => GateEntry::where('status', 'validated')->where('entry_type', 'inward')->orderBy('created_at')->limit(5)->get(),
            'outwardReady' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->count(),
            'outwardQueue' => GateEntry::where('status', 'dock_assigned')->where('entry_type', 'outward')->orderBy('dock_assigned_at')->limit(5)->get(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div wire:poll.10s class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Executive Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Dashboard</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Vehicles cleared by Guard and waiting to be unloaded.</p>
    </section>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <x-icon name="calendar-clock" class="w-5 h-5 mb-2 text-[var(--text-muted)]" />
            <div class="text-xs" style="color: var(--text-muted);">Awaiting Dock</div>
            <div class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $awaiting }}</div>
        </div>
        <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <x-icon name="warehouse" class="w-5 h-5 mb-2 text-[var(--text-muted)]" />
            <div class="text-xs" style="color: var(--text-muted);">On Dock</div>
            <div class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $onDock }}</div>
        </div>
        <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <x-icon name="check" class="w-5 h-5 mb-2 text-[var(--text-muted)]" />
            <div class="text-xs" style="color: var(--text-muted);">Allotted</div>
            <div class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $allotted }}</div>
        </div>
        <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <x-icon name="truck" class="w-5 h-5 mb-2 text-[var(--status-warning)]" />
            <div class="text-xs" style="color: var(--text-muted);">In Progress</div>
            <div class="text-2xl font-bold mt-1" style="color: var(--status-warning);">{{ $inProgress }}</div>
        </div>
        <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <x-icon name="package-search" class="w-5 h-5 mb-2 text-[var(--status-good)]" />
            <div class="text-xs" style="color: var(--text-muted);">Sent to QC</div>
            <div class="text-2xl font-bold mt-1" style="color: var(--status-good);">{{ $readyForQc }}</div>
        </div>
    </div>

    <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-sm" style="color: var(--text-primary);">Cleared vehicles awaiting a loading dock</h2>
            @if ($awaiting > $queue->count())
                <a href="{{ route('unloading.loading-desk') }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">View all {{ $awaiting }} →</a>
            @endif
        </div>
        @if ($queue->isEmpty())
            <p class="text-sm py-4" style="color: var(--text-muted);">Nothing waiting — cleared vehicles will show up here.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($queue as $g)
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                        </div>
                        <span class="text-xs font-medium shrink-0" style="color: var(--brand);">View →</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-sm" style="color: var(--text-primary);">Outward dispatches ready to load</h2>
            @if ($outwardReady > $outwardQueue->count())
                <a href="{{ route('unloading.outward') }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">View all {{ $outwardReady }} →</a>
            @endif
        </div>
        @if ($outwardQueue->isEmpty())
            <p class="text-sm py-4" style="color: var(--text-muted);">Nothing waiting — approved dispatches will show up here.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($outwardQueue as $g)
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name }} · {{ $g->material }} · {{ $g->loading_dock }}</div>
                        </div>
                        <span class="text-xs font-medium shrink-0" style="color: var(--brand);">View →</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
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
