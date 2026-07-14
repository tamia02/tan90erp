@extends('tan90.master-data.layout')

@section('title', 'Approval Queue')
@section('page-title', 'Approval Queue')
@section('page-subtitle', $rows->count() . ' records awaiting governance action')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Governance Workspace</p>
      <h2>Approval Queue</h2>
      <p>Maker-checker workspace for draft, pending and review-stage master records.</p>
    </div>
  </div>

  <section class="kpi-grid">
    <article class="card kpi-card"><div class="kpi-label">Total Pending</div><div class="kpi-value">{{ $rows->count() }}</div></article>
    <article class="card kpi-card"><div class="kpi-label">Draft</div><div class="kpi-value">{{ $rows->where('status', 'draft')->count() }}</div></article>
    <article class="card kpi-card"><div class="kpi-label">In Review</div><div class="kpi-value">{{ $rows->where('status', 'review')->count() }}</div></article>
    <article class="card kpi-card"><div class="kpi-label">Pending</div><div class="kpi-value">{{ $rows->where('status', 'pending')->count() }}</div></article>
  </section>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Record</th><th>Module</th><th>Status</th><th>Updated</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        @forelse ($rows as $row)
          <tr>
            <td>
              <a class="cell-main" href="{{ route('tan90.master-data.show', [$row['slug'], $row['id']]) }}">{{ $row['name'] }}</a>
              <div class="cell-sub code">{{ $row['code'] }}</div>
            </td>
            <td>{{ $row['module'] }}</td>
            <td>@include('tan90.master-data.partials.status-badge', ['value' => $row['status']])</td>
            <td>{{ optional($row['updated_at'])->format('d M Y, H:i') }}</td>
            <td>
              <div class="row-actions">
                <form method="POST" action="{{ route('tan90.master-data.approve', [$row['slug'], $row['id']]) }}">
                  @csrf <button class="btn btn-sm btn-success" type="submit">Approve</button>
                </form>
                <form method="POST" action="{{ route('tan90.master-data.reject', [$row['slug'], $row['id']]) }}">
                  @csrf <button class="btn btn-sm btn-danger" type="submit">Reject</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">✓</div><h3>No pending approvals</h3><p>All master records are currently clear.</p></div></div></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
@endsection
