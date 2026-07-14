@extends('tan90.master-data.layout')

@section('title', $changeRequest->request_no)
@section('page-title', $changeRequest->request_no)
@section('page-subtitle', $entity['title'] . ' · ' . $changeRequest->record_code)

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Change Request</p>
      <h2>{{ $changeRequest->request_no }}</h2>
      <p>{{ $changeRequest->reason }}</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.master-data.change-requests.index') }}">← Back</a>
      @if (in_array($changeRequest->approval_status, ['pending', 'review']))
        @can('approve', $entity['model'])
          <form method="POST" action="{{ route('tan90.master-data.change-requests.approve', $changeRequest->id) }}">
            @csrf <button class="btn btn-success" type="submit">Approve & Apply</button>
          </form>
          <form method="POST" action="{{ route('tan90.master-data.change-requests.reject', $changeRequest->id) }}">
            @csrf <button class="btn btn-danger" type="submit">Reject</button>
          </form>
        @endcan
      @endif
    </div>
  </div>

  <div class="detail-layout">
    <section class="card">
      <div class="card-head"><div><h3>Proposed Changes</h3><p>Field-by-field diff</p></div>@include('tan90.master-data.partials.status-badge', ['value' => $changeRequest->approval_status])</div>
      <div class="card-body">
        <div class="detail-summary">
          @foreach ($changeRequest->proposed_changes as $field => $newValue)
            <div class="detail-field">
              <span>{{ Str::title(str_replace('_', ' ', $field)) }}</span>
              <strong>{{ $changeRequest->previous_values[$field] ?? '—' }} → {{ $newValue }}</strong>
            </div>
          @endforeach
        </div>
      </div>
    </section>
    <aside class="card">
      <div class="card-head"><div><h3>Request Info</h3></div></div>
      <div class="card-body">
        <div class="timeline">
          <div class="timeline-item"><span class="timeline-dot"></span><h4>Requested</h4><p>{{ $changeRequest->created_at?->format('d M Y, H:i') }} by {{ $changeRequest->requester?->name }}</p></div>
          @if ($changeRequest->reviewed_at)
            <div class="timeline-item"><span class="timeline-dot"></span><h4>Reviewed</h4><p>{{ $changeRequest->reviewed_at?->format('d M Y, H:i') }} by {{ $changeRequest->reviewer?->name }}<br>{{ $changeRequest->review_notes }}</p></div>
          @endif
        </div>
      </div>
    </aside>
  </div>

  <section class="card" style="margin-top:15px">
    <div class="card-head"><div><h3>Applied Versions</h3><p>Effective-dated snapshots written on approval</p></div></div>
    <div class="card-body">
      @forelse ($changeRequest->versions as $version)
        <div class="detail-field" style="margin-bottom:8px">
          <span>Version {{ $version->version_number }} · {{ $version->effective_from?->format('d M Y, H:i') }}</span>
        </div>
      @empty
        <div class="empty-state"><div><div class="empty-icon">↔</div><h3>Not yet applied</h3><p>A version snapshot is written once this request is approved.</p></div></div>
      @endforelse
    </div>
  </section>
@endsection
