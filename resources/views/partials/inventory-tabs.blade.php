{{-- Header shared by the Inventory screens (the left menu links them, so no step tabs). Expects $title and $subtitle; $current is no longer used. --}}
<section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold" style="color: var(--text-primary);">{{ $title }}</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $subtitle }}</p>
        </div>
        @if (!empty($actionUrl) && !empty($actionLabel))
            <a href="{{ $actionUrl }}" data-testid="{{ $actionTestId ?? 'inventory-header-action' }}" class="inline-flex min-h-11 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background:var(--brand);">
                {{ $actionLabel }}
            </a>
        @endif
    </div>
</section>
@if (session('success'))
    <div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
@endif
