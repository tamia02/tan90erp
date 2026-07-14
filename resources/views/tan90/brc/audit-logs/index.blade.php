@extends('tan90.brc.layout')

@section('title', 'Audit Trail')
@section('page-title', 'Audit Trail')
@section('page-subtitle', $logs->total() . ' events')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Governance</p>
      <h2>Audit Trail</h2>
      <p>Immutable log of every create/update/submit/approve/reject/release/roll-up action.</p>
    </div>
  </div>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Action</th><th>Record</th><th>User</th><th>When</th><th>Description</th></tr></thead>
        <tbody>
          @forelse ($logs as $log)
            <tr>
              <td>{{ $log->action }}</td>
              <td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
              <td>{{ $log->user->name ?? 'System' }}</td>
              <td>{{ $log->created_at?->format('d M Y, H:i') }}</td>
              <td>{{ $log->description }}</td>
            </tr>
          @empty
            <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">AU</div><h3>No audit events yet</h3></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $logs->links() }}</div>
  </section>
@endsection
