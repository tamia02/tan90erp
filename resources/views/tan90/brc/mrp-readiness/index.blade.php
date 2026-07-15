<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">MRP Readiness</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="mb-5">
      <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Planning</p>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">Blocked when the recipe, BOM, routing or standard cost isn't released for a finished good.</p>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Finished Good</th><th class="px-4 py-2.5 font-medium">Status</th><th class="px-4 py-2.5 font-medium">Blockers</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($finishedGoods as $fg)
              @php($result = $readiness[$fg->id])
              <tr onclick="window.location='{{ route('tan90.brc.mrp-readiness.show', $fg->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $fg->name }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $result['ready'] ? 'mrp_ready' : 'pending'])</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $result['ready'] ? 'None' : count($result['blockers']) . ' blocker(s)' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>
</x-app-layout>
