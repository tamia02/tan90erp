<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Engineering Change Orders</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="mb-5">
      <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Change Control</p>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">Raised automatically whenever a released recipe or BOM is superseded by a new revision.</p>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Code</th><th class="px-4 py-2.5 font-medium">Object</th><th class="px-4 py-2.5 font-medium">Reason</th><th class="px-4 py-2.5 font-medium">Requested By</th><th class="px-4 py-2.5 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($ecos as $eco)
              <tr onclick="window.location='{{ route('tan90.brc.eco.show', $eco->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $eco->code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ ucfirst($eco->object_type) }} #{{ $eco->object_id }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $eco->reason }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $eco->requestedBy->name ?? '—' }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $eco->status])</td>
              </tr>
            @empty
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No engineering changes yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $ecos->links() }}</div>
    </div>
  </div>
</x-app-layout>
