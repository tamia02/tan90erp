@extends('tan90.brc.layout')

@section('title', 'Routings')
@section('page-title', 'Routings')
@section('page-subtitle', $routings->total() . ' routings')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Manufacturing</p>
      <h2>Routings</h2>
      <p>Operation sequences and work centers for each finished good.</p>
    </div>
    <div class="page-actions">
      @can('create', \App\Models\Tan90\BomRecipeCosting\Routing::class)
        <a class="btn btn-primary" href="{{ route('tan90.brc.routings.create') }}">＋ New Routing</a>
      @endcan
    </div>
  </div>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Finished Good</th><th>Name</th><th>Approval</th></tr></thead>
        <tbody>
          @forelse ($routings as $routing)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.routings.show', $routing->id) }}'">
              <td>{{ $routing->code }}</td>
              <td>{{ $routing->finishedGood->name ?? '—' }}</td>
              <td>{{ $routing->name }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $routing->approval_status])</td>
            </tr>
          @empty
            <tr><td colspan="4"><div class="empty-state"><div><div class="empty-icon">RT</div><h3>No routings yet</h3></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $routings->links() }}</div>
  </section>
@endsection
