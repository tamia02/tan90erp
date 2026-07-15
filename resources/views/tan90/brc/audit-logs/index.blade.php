<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Audit Trail</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="mb-5">
      <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Governance</p>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">Immutable log of every create/update/submit/approve/reject/release/roll-up action.</p>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Action</th><th class="px-4 py-2.5 font-medium">Record</th><th class="px-4 py-2.5 font-medium">User</th><th class="px-4 py-2.5 font-medium">When</th><th class="px-4 py-2.5 font-medium">Description</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($logs as $log)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $log->action }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->user->name ?? 'System' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->description }}</td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No audit events yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $logs->links() }}</div>
    </div>
  </div>
</x-app-layout>
