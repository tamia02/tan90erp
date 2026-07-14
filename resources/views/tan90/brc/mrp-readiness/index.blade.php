@extends('tan90.brc.layout')

@section('title', 'MRP Readiness')
@section('page-title', 'MRP Readiness')
@section('page-subtitle', $finishedGoods->count() . ' finished goods')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Planning</p>
      <h2>MRP Readiness</h2>
      <p>Blocked when the recipe, BOM, routing or standard cost isn't released for a finished good.</p>
    </div>
  </div>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Finished Good</th><th>Status</th><th>Blockers</th></tr></thead>
        <tbody>
          @foreach ($finishedGoods as $fg)
            @php($result = $readiness[$fg->id])
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.mrp-readiness.show', $fg->id) }}'">
              <td>{{ $fg->name }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $result['ready'] ? 'mrp_ready' : 'pending'])</td>
              <td>{{ $result['ready'] ? 'None' : count($result['blockers']) . ' blocker(s)' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </section>
@endsection
