<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $component->name }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <p class="text-sm mb-5" style="color: var(--text-secondary);">Where Used</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Used in Recipes</h3>
        @forelse ($usage['recipe_lines'] as $line)
          <div class="py-2" style="border-top: 1px solid var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $line->recipeVersion->recipe->code ?? '—' }}</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ $line->recipeVersion->revision_code ?? '' }} · {{ $line->percentage }}%</div>
          </div>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">Not used in any recipe.</p>
        @endforelse
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Used in BOMs</h3>
        @forelse ($usage['bom_lines'] as $line)
          <div class="py-2" style="border-top: 1px solid var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $line->bomVersion->bom->code ?? '—' }}</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ $line->bomVersion->revision_code ?? '' }} · Qty {{ $line->quantity }}</div>
          </div>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">Not used in any BOM.</p>
        @endforelse
      </div>
    </div>
  </div>
</x-app-layout>
