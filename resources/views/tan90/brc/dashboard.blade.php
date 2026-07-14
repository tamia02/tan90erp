@extends('tan90.brc.layout')

@section('title', 'Command Center')
@section('page-title', 'Command Center')
@section('page-subtitle', 'BOM, Recipe & Costing overview')

@section('content')
  <section class="kpi-grid">
    <div class="kpi-card"><span>Recipes</span><strong>{{ $counts['recipes'] }}</strong></div>
    <div class="kpi-card"><span>BOMs</span><strong>{{ $counts['boms'] }}</strong></div>
    <div class="kpi-card"><span>Released</span><strong>{{ $counts['released'] }}</strong></div>
    <div class="kpi-card"><span>MRP Ready</span><strong>{{ $counts['mrp_ready'] }}</strong></div>
    <div class="kpi-card"><span>Open ECOs</span><strong>{{ $counts['open_ecos'] }}</strong></div>
  </section>

  <section class="flow-ribbon">
    <a class="flow-node" href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'technical_review']) }}"><strong>{{ $counts['pending_technical_review'] }}</strong><span>Technical Review</span></a>
    <span class="flow-arrow">→</span>
    <a class="flow-node" href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'qa_review']) }}"><strong>{{ $counts['pending_qa_review'] }}</strong><span>QA Review</span></a>
    <span class="flow-arrow">→</span>
    <a class="flow-node" href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'cost_review']) }}"><strong>{{ $counts['pending_cost_review'] }}</strong><span>Cost Review</span></a>
    <span class="flow-arrow">→</span>
    <a class="flow-node" href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'plant_trial']) }}"><strong>{{ $counts['pending_plant_trial'] }}</strong><span>Plant Trial</span></a>
    <span class="flow-arrow">→</span>
    <a class="flow-node" href="{{ route('tan90.brc.recipes.index', ['gate_status' => 'released']) }}"><strong>{{ $counts['released'] }}</strong><span>Release</span></a>
  </section>

  <section class="grid grid-2" style="margin-top:15px">
    <article class="card">
      <div class="card-head"><div><h3>My Tasks</h3><p>Engineering changes you raised, still in draft</p></div></div>
      <div class="card-body">
        @forelse ($myTasks as $eco)
          <a class="mini-row" href="{{ route('tan90.brc.eco.show', $eco->id) }}">
            <div><strong>{{ $eco->code }}</strong><span>{{ $eco->reason }}</span></div>
            @include('tan90.brc.partials.status-badge', ['value' => $eco->status])
          </a>
        @empty
          <div class="empty-state"><div><div class="empty-icon">✓</div><h3>Nothing pending</h3></div></div>
        @endforelse
      </div>
    </article>
    <article class="card">
      <div class="card-head"><div><h3>Recent Revisions</h3><p>Latest recipe activity</p></div></div>
      <div class="card-body">
        @forelse ($recentRevisions as $version)
          <a class="mini-row" href="{{ route('tan90.brc.recipes.show', $version->tan90_recipe_id) }}">
            <div><strong>{{ $version->recipe->finishedGood->name ?? $version->recipe->code }}</strong><span>{{ $version->revision_code }}</span></div>
            @include('tan90.brc.partials.status-badge', ['value' => $version->gate_status])
          </a>
        @empty
          <div class="empty-state"><div><div class="empty-icon">RC</div><h3>No revisions yet</h3></div></div>
        @endforelse
      </div>
    </article>
  </section>
@endsection
