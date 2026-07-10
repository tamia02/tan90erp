<?php

use App\Models\GateEntry;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'gatesByMonth' => GateEntry::all()->groupBy(fn ($g) => $g->created_at->format('M Y'))->map->count(),
            'gatesByStatus' => GateEntry::all()->groupBy('status')->map->count(),
            'skusMapped' => SkuMaster::where('mapped', true)->count(),
            'skusTotal' => SkuMaster::count(),
            'activeVendors' => VendorMaster::where('active', true)->count(),
            'totalVendors' => VendorMaster::count(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <div class="mb-5">
        <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Reports</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Gate entries — last 12 months, plus master-data coverage.</p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">SKUs Mapped</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $skusMapped }} / {{ $skusTotal }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Active Vendors</div>
            <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $activeVendors }} / {{ $totalVendors }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Gate entries — last 12 months</h2>
        @forelse ($gatesByMonth as $month => $count)
            <div class="flex justify-between text-sm py-1.5" style="color: var(--text-secondary);">
                <span>{{ $month }}</span>
                <span style="color: var(--text-primary);">{{ $count }}</span>
            </div>
        @empty
            <p class="text-sm py-2" style="color: var(--text-muted);">No gate entries yet.</p>
        @endforelse
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Gate entries by status</h2>
        @foreach ($gatesByStatus as $status => $count)
            <div class="flex justify-between text-sm py-1.5" style="color: var(--text-secondary);">
                <span class="capitalize">{{ str_replace('_', ' ', $status) }}</span>
                <span style="color: var(--text-primary);">{{ $count }}</span>
            </div>
        @endforeach
    </div>
</div>
