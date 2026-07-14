@extends('tan90.brc.layout')

@section('title', $finishedGood->name . ' Readiness')
@section('page-title', $finishedGood->name)
@section('page-subtitle', 'MRP Readiness')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">MRP Readiness</p>
      <h2>{{ $finishedGood->name }}</h2>
    </div>
    <div class="page-actions"><a class="btn btn-ghost" href="{{ route('tan90.brc.mrp-readiness.index') }}">← Back</a></div>
  </div>

  <section class="card">
    <div class="card-head"><div><h3>{{ $result['ready'] ? 'Ready for MRP' : 'Not Ready' }}</h3></div>@include('tan90.brc.partials.status-badge', ['value' => $result['ready'] ? 'mrp_ready' : 'pending'])</div>
    <div class="card-body">
      @if ($result['ready'])
        <div class="empty-state"><div><div class="empty-icon">✓</div><h3>All required masters are released</h3></div></div>
      @else
        <div class="check-list">
          @foreach ($result['blockers'] as $blocker)
            <div style="border-left:3px solid var(--danger)">{{ $blocker }}</div>
          @endforeach
        </div>
      @endif
    </div>
  </section>
@endsection
