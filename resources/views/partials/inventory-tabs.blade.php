{{-- Header + step tabs shared by the four Inventory screens. Expects $title, $subtitle and $current (route name - request()->routeIs is wrong during Livewire updates). --}}
<section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Inventory · RM/PM → Production → FG → Outward</div>
    <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $title }}</h1>
    <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $subtitle }}</p>
    <nav class="flex flex-wrap gap-2 mt-4" aria-label="Inventory steps">
        @foreach ([
            ['inventory.items', '1. Items & Recipes'],
            ['inventory.production', '2. Create Bundle (Production)'],
            ['inventory.dispatch', '3. Outward Shipment'],
            ['inventory.stock', '4. Stock Summary'],
        ] as [$route, $label])
            <a href="{{ route($route) }}" wire:navigate class="rounded-full border px-3 py-1.5 text-xs font-semibold"
               style="{{ $current === $route ? 'background: var(--brand); color: #fff; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-secondary);' }}">{{ $label }}</a>
        @endforeach
    </nav>
</section>
@if (session('success'))
    <div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
@endif
