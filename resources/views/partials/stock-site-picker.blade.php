{{-- Stock per site: site staff see their own site; Super Admin / no-site users pick one. --}}
@if ($stockSites->isNotEmpty())
    <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
        <label for="stock-site" class="font-medium" style="color: var(--text-primary);">Site</label>
        <select id="stock-site" wire:model.live="stockSite" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);">
            @unless ($required ?? false)<option value="">{{ ($separate ?? false) ? 'All locations (separate rows)' : 'All sites (added up)' }}</option>@endunless
            @foreach ($stockSites as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach
        </select>
        <span class="text-xs" style="color: var(--text-muted);">{{ ($required ?? false) ? 'Stock is used and added at this site.' : 'Stock shown for this site.' }}</span>
    </div>
@elseif ($stockSiteName)
    <div class="mb-4 text-sm" style="color: var(--text-secondary);">Site: <span class="font-semibold" style="color: var(--text-primary);">{{ $stockSiteName }}</span> - stock shown and changed here is this site's.</div>
@endif
