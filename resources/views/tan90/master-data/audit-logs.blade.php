@extends('tan90.master-data.layout')

@section('title', 'Audit Trail')
@section('page-title', 'Immutable Audit Trail')
@section('page-subtitle', 'Read-only evidence of master data activity')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Audit & Compliance</p>
      <h2>Immutable Audit Trail</h2>
      <p>Every create, edit, submit, approve, reject, archive, restore, import and permission change is recorded here and cannot be edited or deleted.</p>
    </div>
  </div>

  <form method="GET" class="toolbar">
    <div class="toolbar-search"><span class="search-glyph">⌕</span><input type="text" name="q" value="{{ request('q') }}" placeholder="Search record or summary"></div>
    <select name="event" onchange="this.form.submit()">
      <option value="">All events</option>
      @foreach ($events as $event)
        <option value="{{ $event }}" @selected(request('event') === $event)>{{ $event }}</option>
      @endforeach
    </select>
    <button class="btn btn-secondary" type="submit">Filter</button>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Event</th><th>Module</th><th>Record</th><th>User</th><th>When</th><th>Summary</th></tr></thead>
      <tbody>
        @forelse ($logs as $log)
          <tr>
            <td>@include('tan90.master-data.partials.status-badge', ['value' => $log->event])</td>
            <td>{{ $log->module }}</td>
            <td class="code">{{ $log->record_label }}</td>
            <td>{{ $log->user?->name ?? 'System' }}</td>
            <td>{{ $log->occurred_at?->format('d M Y, H:i') }}</td>
            <td>{{ $log->summary }}</td>
          </tr>
        @empty
          <tr><td colspan="6"><div class="empty-state"><div><div class="empty-icon">AU</div><h3>No audit events</h3></div></div></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px">{{ $logs->links() }}</div>
@endsection
