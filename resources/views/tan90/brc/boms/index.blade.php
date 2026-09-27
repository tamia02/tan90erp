<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">BOM Register</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Manufacturing</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Production, packaging and service BOMs with revisioned lines.</p>
      </div>
      @can('create', \App\Models\Tan90\BomRecipeCosting\Bom::class)
        <a href="{{ route('tan90.brc.boms.create') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">+ New BOM</a>
      @endcan
    </div>

    <form method="GET" class="flex flex-col sm:flex-row gap-2 mb-4">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search BOMs" class="flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
      <select name="bom_type" onchange="this.form.submit()" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">All types</option>
        @foreach (['production', 'packaging', 'service'] as $type)
          <option value="{{ $type }}" @selected(request('bom_type') === $type)>{{ ucfirst($type) }}</option>
        @endforeach
      </select>
    </form>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Code</th>
              <th class="px-4 py-2.5 font-medium">Finished Good</th>
              <th class="px-4 py-2.5 font-medium">Type</th>
              <th class="px-4 py-2.5 font-medium">Current Revision</th>
              <th class="px-4 py-2.5 font-medium">Gate Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($boms as $bom)
              <tr onclick="window.location='{{ route('tan90.brc.boms.show', $bom->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $bom->code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $bom->finishedGood->name ?? '—' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ ucfirst($bom->bom_type) }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $bom->currentVersion?->revision_code ?? '—' }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $bom->currentVersion?->gate_status])</td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No BOMs yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $boms->links() }}</div>
    </div>
  </div>
</x-app-layout>
