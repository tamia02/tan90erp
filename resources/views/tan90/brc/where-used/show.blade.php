@extends('tan90.brc.layout')

@section('title', 'Where Used — ' . $component->name)
@section('page-title', $component->name)
@section('page-subtitle', 'Where Used')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Component</p>
      <h2>{{ $component->name }}</h2>
    </div>
  </div>

  <section class="grid grid-2">
    <article class="card">
      <div class="card-head"><div><h3>Used in Recipes</h3></div></div>
      <div class="card-body">
        @forelse ($usage['recipe_lines'] as $line)
          <div class="mini-row"><div><strong>{{ $line->recipeVersion->recipe->code ?? '—' }}</strong><span>{{ $line->recipeVersion->revision_code ?? '' }} · {{ $line->percentage }}%</span></div></div>
        @empty
          <div class="empty-state"><div><div class="empty-icon">RC</div><h3>Not used in any recipe</h3></div></div>
        @endforelse
      </div>
    </article>
    <article class="card">
      <div class="card-head"><div><h3>Used in BOMs</h3></div></div>
      <div class="card-body">
        @forelse ($usage['bom_lines'] as $line)
          <div class="mini-row"><div><strong>{{ $line->bomVersion->bom->code ?? '—' }}</strong><span>{{ $line->bomVersion->revision_code ?? '' }} · Qty {{ $line->quantity }}</span></div></div>
        @empty
          <div class="empty-state"><div><div class="empty-icon">BM</div><h3>Not used in any BOM</h3></div></div>
        @endforelse
      </div>
    </article>
  </section>
@endsection
