@extends('tan90.master-data.layout')

@section('title', 'Data Quality Center')
@section('page-title', 'Data Quality Center')
@section('page-subtitle', $open . ' open issues')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Data Governance</p>
      <h2>Data Quality Center</h2>
      <p>Missing fields, duplicates and unverified tax data across master records.</p>
    </div>
    <div class="page-actions">
      <form method="POST" action="{{ route('tan90.master-data.data-quality.scan') }}">
        @csrf
        <button class="btn btn-primary" type="submit">↻ Run Quality Scan</button>
      </form>
    </div>
  </div>

  <section class="kpi-grid">
    <article class="card kpi-card"><div class="kpi-label">Critical</div><div class="kpi-value">{{ $critical }}</div><div class="kpi-trend danger">Blocks publication</div></article>
    <article class="card kpi-card"><div class="kpi-label">Total Open</div><div class="kpi-value">{{ $open }}</div><div class="kpi-trend">Across all domains</div></article>
  </section>

  <section class="card">
    <div class="card-head"><div><h3>Detected Issues</h3><p>Resolve once the underlying record is fixed - the next scan reopens it if the problem persists.</p></div></div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap" style="border:0;border-radius:0">
        <table>
          <thead><tr><th>Rule</th><th>Entity</th><th>Record</th><th>Issue</th><th>Severity</th><th>Owner</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            @forelse ($issues as $issue)
              <tr>
                <td class="code">{{ $issue->rule_code }}</td>
                <td>{{ $issue->entity }}</td>
                <td>{{ $issue->record_label }}</td>
                <td>{{ $issue->issue }}</td>
                <td>@include('tan90.master-data.partials.status-badge', ['value' => $issue->severity])</td>
                <td>{{ $issue->owner }}</td>
                <td>@include('tan90.master-data.partials.status-badge', ['value' => $issue->resolution_status])</td>
                <td>
                  @if ($issue->resolution_status !== 'resolved')
                    <form method="POST" action="{{ route('tan90.master-data.data-quality.resolve', $issue->id) }}">
                      @csrf
                      <button class="btn btn-sm btn-secondary" type="submit">Resolve</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="8"><div class="empty-state"><div><div class="empty-icon">✓</div><h3>No issues detected</h3><p>Run a scan to check current master data.</p></div></div></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <div style="margin-top:12px">{{ $issues->links() }}</div>
@endsection
