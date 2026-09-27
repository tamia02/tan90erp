<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Command Center</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <p class="text-sm mb-4" style="color: var(--text-secondary);">BOM, Recipe & Costing overview</p>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Recipes</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $counts['recipes'] }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">BOMs</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $counts['boms'] }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Released</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $counts['released'] }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">MRP Ready</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $counts['mrp_ready'] }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Open ECOs</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $counts['open_ecos'] }}</div>
      </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
      <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">P0 Release Pipeline</h3>
      <div class="flex items-center gap-2 overflow-x-auto pb-1">
        <a href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'technical_review']) }}" class="shrink-0 rounded-lg border px-3 py-2 text-center min-w-[7rem]" style="border-color: var(--border);">
          <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ $counts['pending_technical_review'] }}</div>
          <div class="text-xs" style="color: var(--text-muted);">Technical Review</div>
        </a>
        <span style="color: var(--text-muted);">→</span>
        <a href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'qa_review']) }}" class="shrink-0 rounded-lg border px-3 py-2 text-center min-w-[7rem]" style="border-color: var(--border);">
          <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ $counts['pending_qa_review'] }}</div>
          <div class="text-xs" style="color: var(--text-muted);">QA Review</div>
        </a>
        <span style="color: var(--text-muted);">→</span>
        <a href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'cost_review']) }}" class="shrink-0 rounded-lg border px-3 py-2 text-center min-w-[7rem]" style="border-color: var(--border);">
          <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ $counts['pending_cost_review'] }}</div>
          <div class="text-xs" style="color: var(--text-muted);">Cost Review</div>
        </a>
        <span style="color: var(--text-muted);">→</span>
        <a href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'plant_trial']) }}" class="shrink-0 rounded-lg border px-3 py-2 text-center min-w-[7rem]" style="border-color: var(--border);">
          <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ $counts['pending_plant_trial'] }}</div>
          <div class="text-xs" style="color: var(--text-muted);">Plant Trial</div>
        </a>
        <span style="color: var(--text-muted);">→</span>
        <a href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'released']) }}" class="shrink-0 rounded-lg border px-3 py-2 text-center min-w-[7rem]" style="border-color: var(--border);">
          <div class="text-lg font-semibold" style="color: var(--status-good);">{{ $counts['released'] }}</div>
          <div class="text-xs" style="color: var(--text-muted);">Release</div>
        </a>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">My Tasks</h3>
        <p class="text-xs -mt-2 mb-3" style="color: var(--text-muted);">Engineering changes you raised, still in draft</p>
        @forelse ($myTasks as $eco)
          <a href="{{ route('tan90.brc.eco.show', $eco->id) }}" class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid var(--border);">
            <div>
              <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $eco->code }}</div>
              <div class="text-xs" style="color: var(--text-muted);">{{ $eco->reason }}</div>
            </div>
            @include('tan90.brc.partials.status-badge', ['value' => $eco->status])
          </a>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">Nothing pending.</p>
        @endforelse
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Recent Revisions</h3>
        <p class="text-xs -mt-2 mb-3" style="color: var(--text-muted);">Latest recipe activity</p>
        @forelse ($recentRevisions as $version)
          <a href="{{ route('tan90.brc.recipes.show', $version->tan90_recipe_id) }}" class="flex items-center justify-between gap-3 py-2.5" style="border-top: 1px solid var(--border);">
            <div>
              <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $version->recipe->finishedGood->name ?? $version->recipe->code }}</div>
              <div class="text-xs" style="color: var(--text-muted);">{{ $version->revision_code }}</div>
            </div>
            @include('tan90.brc.partials.status-badge', ['value' => $version->gate_status])
          </a>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">No revisions yet.</p>
        @endforelse
      </div>
    </div>
  </div>
</x-app-layout>
