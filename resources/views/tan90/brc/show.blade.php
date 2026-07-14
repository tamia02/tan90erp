@extends('tan90.brc.layout')

@section('title', $record->{$config['primary']} ?? $config['singular'])
@section('page-title', $config['singular'] . ' Detail')
@section('page-subtitle', $record->{$config['code']} ?? '')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $config['title'] }} / Detail</p>
      <h2>{{ $record->{$config['primary']} ?? $config['singular'] }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.brc.index', $entity) }}">← Back</a>

      @can('update', $record)
        @if ($record->status !== 'archived')
          <a class="btn btn-secondary" href="{{ route('tan90.brc.edit', [$entity, $record->id]) }}">Edit</a>
        @endif
      @endcan

      @can('submit', $record)
        @if (in_array($record->approval_status, ['draft', 'rejected']))
          <form method="POST" action="{{ route('tan90.brc.submit', [$entity, $record->id]) }}">
            @csrf <button class="btn btn-secondary" type="submit">Submit for Approval</button>
          </form>
        @endif
      @endcan
      @can('approve', $record)
        @if ($record->approval_status === 'review')
          <form method="POST" action="{{ route('tan90.brc.approve', [$entity, $record->id]) }}">
            @csrf <button class="btn btn-success" type="submit">Approve</button>
          </form>
          <form method="POST" action="{{ route('tan90.brc.reject', [$entity, $record->id]) }}">
            @csrf <button class="btn btn-danger" type="submit">Reject</button>
          </form>
        @endif
      @endcan

      @if ($record->status === 'archived')
        @can('restore', $record)
          <form method="POST" action="{{ route('tan90.brc.restore', [$entity, $record->id]) }}">
            @csrf <button class="btn btn-success" type="submit">Restore</button>
          </form>
        @endcan
      @else
        @can('delete', $record)
          <form method="POST" action="{{ route('tan90.brc.destroy', [$entity, $record->id]) }}" data-confirm="Archive this record?">
            @csrf @method('DELETE')
            <button class="btn btn-danger" type="submit">Archive</button>
          </form>
        @endcan
      @endif
    </div>
  </div>

  <div class="detail-layout">
    <section class="card">
      <div class="card-head">
        <div><h3>Record Summary</h3><p>Current values</p></div>
        @include('tan90.brc.partials.status-badge', ['value' => $record->approval_status ?? $record->status])
      </div>
      <div class="card-body">
        <div class="detail-summary">
          @foreach ($record->getAttributes() as $key => $value)
            @continue(in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by']))
            <div class="detail-field">
              <span>{{ Str::title(str_replace('_', ' ', preg_replace('/_id$/', '', $key))) }}</span>
              <strong>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—') }}</strong>
            </div>
          @endforeach
        </div>
      </div>
    </section>
    <aside class="card">
      <div class="card-head"><div><h3>Control Information</h3><p>Governance and audit metadata</p></div></div>
      <div class="card-body">
        <div class="timeline">
          <div class="timeline-item"><span class="timeline-dot"></span><h4>Created</h4><p>{{ $record->created_at?->format('d M Y, H:i') }}</p></div>
          <div class="timeline-item"><span class="timeline-dot"></span><h4>Last Updated</h4><p>{{ $record->updated_at?->format('d M Y, H:i') }}</p></div>
          @if (isset($record->version))
            <div class="timeline-item"><span class="timeline-dot"></span><h4>Version</h4><p>v{{ $record->version }}</p></div>
          @endif
        </div>
      </div>
    </aside>
  </div>

  <section class="card" style="margin-top:15px">
    <div class="card-head"><div><h3>Record Audit</h3><p>Immutable history for this record</p></div></div>
    <div class="card-body">
      @if ($auditTrail->count())
        <div class="timeline">
          @foreach ($auditTrail as $log)
            <div class="timeline-item">
              <span class="timeline-dot"></span>
              <h4>{{ $log->action }} · {{ $log->user?->name ?? 'System' }}</h4>
              <p>{{ $log->created_at?->format('d M Y, H:i') }}<br>{{ $log->description }}</p>
            </div>
          @endforeach
        </div>
      @else
        <div class="empty-state"><div><div class="empty-icon">AU</div><h3>No audit events</h3></div></div>
      @endif
    </div>
  </section>
@endsection
