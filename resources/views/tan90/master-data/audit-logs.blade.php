<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Immutable Audit Trail</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <p class="text-sm mb-5" style="color: var(--text-secondary);">Every create, edit, submit, approve, reject, archive, restore, import and permission change is recorded here and cannot be edited or deleted.</p>

    <form method="GET" class="flex flex-col sm:flex-row gap-2 mb-4">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search record or summary" class="flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
      <select name="event" onchange="this.form.submit()" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">All events</option>
        @foreach ($events as $event)
          <option value="{{ $event }}" @selected(request('event') === $event)>{{ $event }}</option>
        @endforeach
      </select>
    </form>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Event</th><th class="px-4 py-2.5 font-medium">Module</th><th class="px-4 py-2.5 font-medium">Record</th><th class="px-4 py-2.5 font-medium">User</th><th class="px-4 py-2.5 font-medium">When</th><th class="px-4 py-2.5 font-medium">Summary</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($logs as $log)
              <tr style="border-top: 1px solid var(--border);">
                <td class="px-4 py-2.5">@include('tan90.master-data.partials.status-badge', ['value' => $log->event])</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $log->module }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->record_label }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->user?->name ?? 'System' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->occurred_at?->format('d M Y, H:i') }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $log->summary }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No audit events.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $logs->links() }}</div>
    </div>
  </div>
</x-app-layout>
