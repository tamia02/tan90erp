<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Data Quality Center</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">Missing fields, duplicates and unverified tax data across master records.</p>
      <form method="POST" action="{{ route('tan90.master-data.data-quality.scan') }}">
        @csrf
        <button type="submit" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Run Quality Scan</button>
      </form>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-6">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Critical</div><div class="text-2xl font-semibold mt-1" style="color: var(--status-critical);">{{ $critical }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Total Open</div><div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $open }}</div></div>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="px-4 pt-3">
        <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Detected Issues</h3>
        <p class="text-xs mb-2" style="color: var(--text-muted);">Resolve once the underlying record is fixed - the next scan reopens it if the problem persists.</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Rule</th><th class="px-4 py-2.5 font-medium">Entity</th><th class="px-4 py-2.5 font-medium">Record</th><th class="px-4 py-2.5 font-medium">Issue</th><th class="px-4 py-2.5 font-medium">Severity</th><th class="px-4 py-2.5 font-medium">Owner</th><th class="px-4 py-2.5 font-medium">Status</th><th class="px-4 py-2.5 font-medium">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($issues as $issue)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $issue->rule_code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $issue->entity }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $issue->record_label }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $issue->issue }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $issue->severity])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $issue->owner }}</td>
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $issue->resolution_status])</td>
                <td class="px-4 py-2.5">
                  @if ($issue->resolution_status !== 'resolved')
                    <form method="POST" action="{{ route('tan90.master-data.data-quality.resolve', $issue->id) }}">
                      @csrf
                      <button type="submit" class="text-xs font-medium" style="color: var(--brand);">Resolve</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="8" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No issues detected. Run a scan to check current master data.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $issues->links() }}</div>
    </div>
  </div>
</x-app-layout>
