@extends('tan90.brc.layout')

@section('title', 'Recipes')
@section('page-title', 'Recipes')
@section('page-subtitle', $recipes->total() . ' recipes')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Formulation</p>
      <h2>Recipes</h2>
      <p>Formula composition per finished good, with revisioned lines and release gates.</p>
    </div>
    <div class="page-actions">
      @can('create', \App\Models\Tan90\BomRecipeCosting\Recipe::class)
        <a class="btn btn-primary" href="{{ route('tan90.brc.recipes.create') }}">＋ New Recipe</a>
      @endcan
    </div>
  </div>

  <form method="GET" class="toolbar">
    <div class="toolbar-search">
      <span class="search-glyph">⌕</span>
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search recipes">
    </div>
  </form>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Finished Good</th><th>Current Revision</th><th>Gate Status</th><th>Formula %</th></tr></thead>
        <tbody>
          @forelse ($recipes as $recipe)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.recipes.show', $recipe->id) }}'">
              <td>{{ $recipe->code }}</td>
              <td>{{ $recipe->finishedGood->name ?? '—' }}</td>
              <td>{{ $recipe->currentVersion?->revision_code ?? '—' }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $recipe->currentVersion?->gate_status])</td>
              <td>{{ $recipe->formula_tolerance_percent }}% tolerance</td>
            </tr>
          @empty
            <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">RC</div><h3>No recipes yet</h3></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $recipes->links() }}</div>
  </section>
@endsection
