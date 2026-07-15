<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Master Data Control Center</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">One trusted workspace to control Tan90 organization, locations, products, vendors and governed changes.</p>
      <a href="{{ route('tan90.master-data.approval-queue') }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white shrink-0" style="background: var(--brand);">Review Approvals</a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Active SKUs</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($kpis['active_items']) }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Pending Approvals</div>
        <div class="text-2xl font-semibold mt-1" style="color: {{ $kpis['pending_approvals'] > 5 ? 'var(--status-warning)' : 'var(--text-primary)' }};">{{ number_format($kpis['pending_approvals']) }}</div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Active Vendors</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($kpis['active_vendors']) }}</div>
      </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
      @foreach (['legal-entities', 'plants', 'warehouses', 'items', 'vendors', 'roles'] as $slug)
        @php($entityConfig = config("tan90_master_data.entities.$slug"))
        @continue(! $entityConfig)
        <a href="{{ route('tan90.master-data.index', $slug) }}" class="rounded-lg border p-4 hover:bg-black/5" style="background: var(--surface-3); border-color: var(--border);">
          <div class="text-xs font-semibold" style="color: var(--brand);">{{ $entityConfig['icon'] }}</div>
          <div class="text-sm font-medium mt-1" style="color: var(--text-primary);">{{ $entityConfig['title'] }}</div>
          <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $entityConfig['description'] }}</div>
        </a>
      @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Recent Master Changes</h3>
          <a href="{{ route('tan90.master-data.audit-logs') }}" class="text-xs font-medium" style="color: var(--brand);">Audit trail</a>
        </div>
        @forelse ($recentAudit as $log)
          <div class="py-2.5" style="border-top: 1px solid var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $log->event }} · {{ $log->record_label }}</div>
            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $log->user?->name ?? 'System' }} · {{ $log->occurred_at?->format('d M Y, H:i') }} — {{ $log->summary }}</div>
          </div>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">No audit activity yet.</p>
        @endforelse
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Governance Reminders</h3>
        <div class="flex flex-col gap-2 text-sm">
          <div class="flex items-center justify-between"><span style="color: var(--text-secondary);">Maker-checker</span><span class="font-medium" style="color: var(--status-good);">On</span></div>
          <div class="flex items-center justify-between"><span style="color: var(--text-secondary);">Soft delete</span><span class="font-medium" style="color: var(--status-good);">On</span></div>
          <div class="flex items-center justify-between"><span style="color: var(--text-secondary);">Audit logging</span><span class="font-medium" style="color: var(--status-good);">On</span></div>
        </div>
      </div>
    </div>
  </div>
</x-app-layout>
