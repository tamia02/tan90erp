<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Locations</h2>
  </x-slot>

  <div class="max-w-7xl mx-auto space-y-5">
    <div class="flex flex-col gap-4 rounded-2xl border p-5 sm:flex-row sm:items-end sm:justify-between" style="background: var(--surface-3); border-color: var(--border);">
      <div>
        <p class="text-sm" style="color: var(--text-secondary);">Manage business sites, plants and warehouses together. Each record keeps its own operational identity and links.</p>
        <p class="mt-1 text-xs" style="color: var(--text-muted);">Warehouse stock remains tracked by warehouse code; plants and business sites remain separate.</p>
      </div>
      @if ($canCreate)
        <form method="GET" action="{{ route('tan90.master-data.locations.create') }}" class="flex flex-wrap gap-2">
          <label class="sr-only" for="new-location-type">Location type</label>
          <select id="new-location-type" name="type" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
            <option value="site">Business site</option>
            <option value="plant">Plant</option>
            <option value="warehouse">Warehouse</option>
          </select>
          <button class="min-h-11 rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background: var(--brand);">+ Add location</button>
        </form>
      @endif
    </div>

    <form method="GET" action="{{ route('tan90.master-data.locations.index') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_220px_auto]">
      <input type="search" name="search" value="{{ $search }}" placeholder="Search code, name, city or manager…" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
      <select name="type" class="min-h-11 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">All location types</option>
        <option value="site" @selected($filter === 'site')>Business site</option>
        <option value="plant" @selected($filter === 'plant')>Plant</option>
        <option value="warehouse" @selected($filter === 'warehouse')>Warehouse</option>
      </select>
      <button class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Apply filters</button>
    </form>

    <div class="overflow-x-auto rounded-2xl border" style="background: var(--surface-3); border-color: var(--border);">
      <table class="w-full min-w-[760px] text-sm">
        <thead><tr class="text-left text-xs uppercase" style="color: var(--text-muted);">
          <th class="px-4 py-3">Code</th><th class="px-4 py-3">Name</th><th class="px-4 py-3">Location type</th><th class="px-4 py-3">Parent / site</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th>
        </tr></thead>
        <tbody>
          @forelse ($rows as $row)
            @php
              $record = $row['record'];
              $siteName = $row['kind'] === 'site' ? '—' : ($record->location?->name ?? 'Not linked');
              if ($row['kind'] === 'warehouse' && $record->plant) $siteName .= ' · '.$record->plant->name;
            @endphp
            <tr style="border-top: 1px solid var(--border);">
              <td class="px-4 py-3 font-mono text-xs">{{ $record->code }}</td>
              <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $record->name }}</td>
              <td class="px-4 py-3"><span class="rounded-full border px-2.5 py-1 text-xs" style="border-color: var(--border);">{{ $row['label'] }}</span></td>
              <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $siteName }}</td>
              <td class="px-4 py-3 text-xs">{{ ucfirst($record->approval_status ?? $record->status ?? 'active') }}</td>
              <td class="px-4 py-3 text-right"><a href="{{ route('tan90.master-data.show', [$row['entity'], $record->id]) }}" class="font-medium" style="color: var(--brand);">Open</a>
                @if (auth()->user()?->isAdmin()) <a href="{{ route('tan90.master-data.edit', [$row['entity'], $record->id]) }}" class="ml-3 text-xs" style="color: var(--text-secondary);">Edit</a> @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No locations match these filters.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="border-t px-4 py-3 text-xs" style="border-color: var(--border); color: var(--text-muted);">{{ $rows->count() }} records · updates continue through the existing governed master-data forms.</div>
    </div>
  </div>
</x-app-layout>
