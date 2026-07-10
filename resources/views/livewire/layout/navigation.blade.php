<?php

use App\Livewire\Actions\Logout;
use App\Support\RoleNavigation;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'navItems' => $user ? RoleNavigation::forRole($user->role) : [],
        ];
    }
}; ?>

<aside class="w-64 shrink-0 border-r flex flex-col" style="background: var(--surface-1); border-color: var(--border);">
    <div class="flex items-center gap-2.5 px-5 py-5 border-b" style="border-color: var(--border);">
        <div class="w-9 h-9 rounded-lg grid place-items-center font-bold text-sm text-white" style="background: var(--brand);">
            T90
        </div>
        <span class="font-semibold tracking-wide" style="color: var(--text-primary);">Tan90 ERP</span>
    </div>

    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5">
        @foreach ($navItems as $item)
            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @class([
                    'flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                ])
                style="{{ request()->routeIs($item['route']) ? 'background: var(--brand-bg); color: var(--brand);' : 'color: var(--text-secondary);' }}"
            >
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="px-3 py-4 border-t" style="border-color: var(--border);">
        <div class="px-2 mb-2">
            <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ auth()->user()->name }}</div>
            <div class="text-xs truncate" style="color: var(--text-muted);">{{ auth()->user()->role->label() }}</div>
        </div>
        <button
            wire:click="logout"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors"
            style="color: var(--status-critical);"
        >
            Log out
        </button>
    </div>
</aside>
