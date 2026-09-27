<?php

use App\Support\NotificationCenter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return ['notices' => NotificationCenter::forRole(auth()->user()->role)];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <h1 class="text-2xl font-bold" style="color: var(--text-primary);">Notifications</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Alerts relevant to your role, computed from live data.</p>
    </section>

    <div class="flex flex-col gap-2">
        @forelse ($notices as $n)
            @php($tag = isset($n['url']) ? 'a' : 'div')
            <{{ $tag }} @if(isset($n['url'])) href="{{ $n['url'] }}" wire:navigate @endif class="rounded-lg border p-4 block" style="background: var(--surface-3); border-color: var(--border); border-left: 3px solid {{ $n['tone'] === 'critical' ? 'var(--status-critical)' : ($n['tone'] === 'warning' ? 'var(--status-warning)' : 'var(--status-good)') }};">
                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $n['title'] }}</div>
                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $n['detail'] }}</div>
            </{{ $tag }}>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing needs your attention right now.</div>
        @endforelse
    </div>
</div>
