@extends('tan90.brc.layout')

@section('title', $eco->code)
@section('page-title', $eco->code)
@section('page-subtitle', ucfirst($eco->object_type) . ' change order')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Engineering Change Order</p>
      <h2>{{ $eco->code }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.brc.eco.index') }}">← Back</a>
      @can('approve', $eco)
        @if ($eco->status === 'draft')
          <form method="POST" action="{{ route('tan90.brc.eco.approve', $eco->id) }}">
            @csrf <button class="btn btn-success" type="submit">Approve</button>
          </form>
        @elseif ($eco->status === 'approved')
          <form method="POST" action="{{ route('tan90.brc.eco.implement', $eco->id) }}">
            @csrf <button class="btn btn-primary" type="submit">Mark Implemented</button>
          </form>
        @endif
      @endcan
    </div>
  </div>

  <div class="detail-layout">
    <section class="card">
      <div class="card-head"><div><h3>Change Details</h3></div>@include('tan90.brc.partials.status-badge', ['value' => $eco->status])</div>
      <div class="card-body">
        <div class="detail-summary">
          <div class="detail-field"><span>Object Type</span><strong>{{ ucfirst($eco->object_type) }}</strong></div>
          <div class="detail-field"><span>Object ID</span><strong>#{{ $eco->object_id }}</strong></div>
          <div class="detail-field"><span>Reason</span><strong>{{ $eco->reason }}</strong></div>
          <div class="detail-field"><span>Requested By</span><strong>{{ $eco->requestedBy->name ?? '—' }}</strong></div>
          <div class="detail-field"><span>Requested At</span><strong>{{ $eco->requested_at?->format('d M Y, H:i') }}</strong></div>
          <div class="detail-field"><span>Approved By</span><strong>{{ $eco->approvedBy->name ?? '—' }}</strong></div>
        </div>
        @if ($eco->description)
          <p style="margin-top:12px;color:var(--muted)">{{ $eco->description }}</p>
        @endif
      </div>
    </section>
    <aside class="card">
      <div class="card-head"><div><h3>Change Impacts</h3></div></div>
      <div class="card-body">
        @forelse ($eco->changeImpacts as $impact)
          <div class="mini-row"><div><strong>{{ ucfirst($impact->impacted_object_type) }} #{{ $impact->impacted_object_id }}</strong><span>{{ $impact->impact_description }}</span></div></div>
        @empty
          <div class="empty-state"><div><div class="empty-icon">CI</div><h3>No downstream impacts recorded</h3></div></div>
        @endforelse
      </div>
    </aside>
  </div>
@endsection
