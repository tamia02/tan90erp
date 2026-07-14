@extends('tan90.master-data.layout')

@section('title', 'Change Requests')
@section('page-title', 'Master Change Requests')
@section('page-subtitle', 'Governed critical-field changes on already-approved records')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Governance Workspace</p>
      <h2>Master Change Requests</h2>
      <p>Every critical-field edit on an approved record opens a request here instead of saving directly.</p>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Request No.</th><th>Entity</th><th>Record</th><th>Fields</th><th>Requested By</th><th>Priority</th><th>Status</th></tr></thead>
      <tbody>
        @forelse ($requests as $cr)
          <tr>
            <td><a class="cell-main" href="{{ route('tan90.master-data.change-requests.show', $cr->id) }}">{{ $cr->request_no }}</a></td>
            <td>{{ $cr->entity_type }}</td>
            <td class="code">{{ $cr->record_code }}</td>
            <td>{{ implode(', ', array_keys($cr->proposed_changes)) }}</td>
            <td>{{ $cr->requester?->name }}</td>
            <td>{{ $cr->priority }}</td>
            <td>@include('tan90.master-data.partials.status-badge', ['value' => $cr->approval_status])</td>
          </tr>
        @empty
          <tr><td colspan="7"><div class="empty-state"><div><div class="empty-icon">CR</div><h3>No change requests</h3></div></div></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px">{{ $requests->links() }}</div>
@endsection
