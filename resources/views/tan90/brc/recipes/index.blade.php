<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Recipes</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Formulation</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Formula composition per finished good, with revisioned lines and release gates.</p>
      </div>
      @can('create', \App\Models\Tan90\BomRecipeCosting\Recipe::class)
        <a href="{{ route('tan90.brc.recipes.create') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">+ New Recipe</a>
      @endcan
    </div>

    <form method="GET" class="mb-4">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search recipes" class="w-full sm:w-80 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
    </form>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Code</th>
              <th class="px-4 py-2.5 font-medium">Finished Good</th>
              <th class="px-4 py-2.5 font-medium">Current Revision</th>
              <th class="px-4 py-2.5 font-medium">Gate Status</th>
              <th class="px-4 py-2.5 font-medium">Formula %</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($recipes as $recipe)
              <tr onclick="window.location='{{ route('tan90.brc.recipes.show', $recipe->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $recipe->code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $recipe->finishedGood->name ?? '—' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $recipe->currentVersion?->revision_code ?? '—' }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $recipe->currentVersion?->gate_status])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $recipe->formula_tolerance_percent }}% tolerance</td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No recipes yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $recipes->links() }}</div>
    </div>
  </div>
</x-app-layout>
