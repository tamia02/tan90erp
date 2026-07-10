<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $byBin = LedgerEntry::where('bucket', 'available')
            ->get()
            ->groupBy('bin')
            ->map(function ($group) {
                return $group->groupBy('sku')->map(fn ($skuGroup) => $skuGroup->sum('qty'));
            });

        return ['byBin' => $byBin];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Shelf &amp; Bin</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">What's currently sitting in each bin.</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @forelse ($byBin as $bin => $skus)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium mb-2" style="color: var(--text-primary);">{{ $bin }}</div>
                @foreach ($skus as $sku => $qty)
                    <div class="text-xs py-1 flex justify-between" style="color: var(--text-secondary);">
                        <span>{{ $sku }}</span>
                        <span style="color: var(--text-primary);">{{ $qty }}</span>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="text-center text-sm py-10 sm:col-span-2" style="color: var(--text-muted);">No bins in use yet.</div>
        @endforelse
    </div>
</div>
