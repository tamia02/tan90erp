<?php

use App\Models\UnloadingRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $active = UnloadingRecord::whereNull('completed_at')->with('gateEntry')->get();

        $areas = ['Staging Bay 1', 'Staging Bay 2', 'Staging Bay 3', 'Staging Bay 4'];
        $byArea = collect($areas)->mapWithKeys(fn ($area) => [$area => $active->where('staging_area', $area)]);

        return ['byArea' => $byArea];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Staging Areas</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">What's currently occupying each staging bay.</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @foreach ($byArea as $area => $records)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-sm font-medium mb-2" style="color: var(--text-primary);">{{ $area }}</div>
                @if ($records->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">Empty</p>
                @else
                    @foreach ($records as $r)
                        <div class="text-xs py-1" style="color: var(--text-secondary);">{{ $r->gateEntry?->gate_no }} · {{ $r->gateEntry?->material }}</div>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>
</div>
