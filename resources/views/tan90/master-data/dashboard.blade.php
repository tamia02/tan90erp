@extends('tan90.master-data.layout')

@section('title', 'Command Center')
@section('page-title', 'Master Data Control Center')
@section('page-subtitle', 'Governed enterprise configuration')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Master Data & Configuration</p>
      <h2>Enterprise Control Center</h2>
      <p>One trusted workspace to control Tan90 organization, locations, products, vendors and governed changes.</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-primary" href="{{ route('tan90.master-data.approval-queue') }}">Review Approvals</a>
    </div>
  </div>

  <div class="flow-ribbon">
    <span class="flow-node active">1. Create Master</span><span class="flow-arrow">→</span>
    <span class="flow-node">2. Validate</span><span class="flow-arrow">→</span>
    <span class="flow-node">3. Submit</span><span class="flow-arrow">→</span>
    <span class="flow-node">4. Maker-Checker Approval</span><span class="flow-arrow">→</span>
    <span class="flow-node">5. Publish & Audit</span>
  </div>

  <section class="kpi-grid">
    <article class="card kpi-card">
      <div class="kpi-label">Active SKUs</div>
      <div class="kpi-value">{{ number_format($kpis['active_items']) }}</div>
      <div class="kpi-trend positive">Authoritative product records</div>
    </article>
    <article class="card kpi-card">
      <div class="kpi-label">Pending Approvals</div>
      <div class="kpi-value">{{ number_format($kpis['pending_approvals']) }}</div>
      <div class="kpi-trend {{ $kpis['pending_approvals'] > 5 ? 'warning' : 'positive' }}">Records awaiting action</div>
    </article>
    <article class="card kpi-card">
      <div class="kpi-label">Active Vendors</div>
      <div class="kpi-value">{{ number_format($kpis['active_vendors']) }}</div>
      <div class="kpi-trend positive">Business partner records</div>
    </article>
  </section>

  <section class="module-grid">
    @foreach (['legal-entities', 'plants', 'warehouses', 'items', 'vendors', 'roles'] as $slug)
      @php($entityConfig = config("tan90_master_data.entities.$slug"))
      @continue(! $entityConfig)
      <a class="card module-card" href="{{ route('tan90.master-data.index', $slug) }}" style="color:inherit">
        <div class="module-icon">{{ $entityConfig['icon'] }}</div>
        <h3>{{ $entityConfig['title'] }}</h3>
        <p>{{ $entityConfig['description'] }}</p>
      </a>
    @endforeach
  </section>

  <section class="grid grid-2" style="margin-top:16px">
    <article class="card">
      <div class="card-head">
        <div><h3>Recent Master Changes</h3><p>Latest auditable activity</p></div>
        <a class="btn btn-sm btn-ghost" href="{{ route('tan90.master-data.audit-logs') }}">Audit trail</a>
      </div>
      <div class="card-body">
        <div class="timeline">
          @forelse ($recentAudit as $log)
            <div class="timeline-item">
              <span class="timeline-dot"></span>
              <h4>{{ $log->event }} · {{ $log->record_label }}</h4>
              <p>{{ $log->user?->name ?? 'System' }} · {{ $log->occurred_at?->format('d M Y, H:i') }}<br>{{ $log->summary }}</p>
            </div>
          @empty
            <p style="color:var(--muted)">No audit activity yet.</p>
          @endforelse
        </div>
      </div>
    </article>
    <article class="card">
      <div class="card-head"><div><h3>Governance Reminders</h3><p>Module policy in effect</p></div></div>
      <div class="card-body bar-list">
        <div class="bar-row"><div class="bar-label">Maker-checker</div><div class="progress"><span style="width:100%"></span></div><div class="bar-value">On</div></div>
        <div class="bar-row"><div class="bar-label">Soft delete</div><div class="progress"><span style="width:100%"></span></div><div class="bar-value">On</div></div>
        <div class="bar-row"><div class="bar-label">Audit logging</div><div class="progress"><span style="width:100%"></span></div><div class="bar-value">On</div></div>
      </div>
    </article>
  </section>
@endsection
