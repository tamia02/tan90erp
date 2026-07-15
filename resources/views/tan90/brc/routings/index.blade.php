<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Routings</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Manufacturing</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Operation sequences and work centers for each finished good.</p>
      </div>
      @can('create', \App\Models\Tan90\BomRecipeCosting\Routing::class)
        <a href="{{ route('tan90.brc.routings.create') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">+ New Routing</a>
      @endcan
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Code</th><th class="px-4 py-2.5 font-medium">Finished Good</th><th class="px-4 py-2.5 font-medium">Name</th><th class="px-4 py-2.5 font-medium">Approval</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($routings as $routing)
              <tr onclick="window.location='{{ route('tan90.brc.routings.show', $routing->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $routing->code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $routing->finishedGood->name ?? '—' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $routing->name }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $routing->approval_status])</td>
              </tr>
            @empty
              <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No routings yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $routings->links() }}</div>
    </div>
  </div>
</x-app-layout>
