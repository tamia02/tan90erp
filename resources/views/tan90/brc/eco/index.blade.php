@extends('tan90.brc.layout')

@section('title', 'Engineering Change Orders')
@section('page-title', 'Engineering Change Orders')
@section('page-subtitle', $ecos->total() . ' change orders')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Change Control</p>
      <h2>Engineering Change Orders</h2>
      <p>Raised automatically whenever a released recipe or BOM is superseded by a new revision.</p>
    </div>
  </div>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Object</th><th>Reason</th><th>Requested By</th><th>Status</th></tr></thead>
        <tbody>
          @forelse ($ecos as $eco)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.eco.show', $eco->id) }}'">
              <td>{{ $eco->code }}</td>
              <td>{{ ucfirst($eco->object_type) }} #{{ $eco->object_id }}</td>
              <td>{{ $eco->reason }}</td>
              <td>{{ $eco->requestedBy->name ?? '—' }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $eco->status])</td>
            </tr>
          @empty
            <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">EC</div><h3>No engineering changes yet</h3></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $ecos->links() }}</div>
  </section>
@endsection
