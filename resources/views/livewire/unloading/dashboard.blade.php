<?php

use App\Models\AuditLogEntry;
use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['Unloading', 'Store Executive'];

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
            'awaiting' => GateEntry::where('status', 'validated')->count(),
            'inProgress' => GateEntry::where('status', 'unloading')->count(),
            'readyForQc' => GateEntry::where('status', 'grn')->count(),
            'queue' => GateEntry::where('status', 'validated')->orderBy('created_at')->limit(5)->get(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Store Executive Dashboard</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Vehicles cleared by Guard and waiting to be unloaded.</p>

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Awaiting Unloading</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $awaiting }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">In Progress</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $inProgress }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Sent to QC</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $readyForQc }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Unloading queue</h2>
        @if ($queue->isEmpty())
            <p class="text-sm py-4" style="color: var(--text-muted);">Nothing waiting — cleared vehicles will show up here.</p>
        @else
            <div class="flex flex-col divide-y" style="border-color: var(--border);">
                @foreach ($queue as $g)
                    <div class="py-3 flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $g->vendor_name ?? $g->vehicle_number }} · {{ $g->material }}</div>
                        </div>
                        <a href="{{ route('unloading.desk') }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">Unload →</a>
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
                <div class="py-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
                </div>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
