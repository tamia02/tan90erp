<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">Cost Sheets</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="mb-5">
      <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Costing</p>
      <p class="text-sm mt-1" style="color: var(--text-secondary);">Standard cost per finished good and cost period, rolled up from BOM + routing rates.</p>
    </div>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="px-4 py-2.5 font-medium">Code</th><th class="px-4 py-2.5 font-medium">Finished Good</th><th class="px-4 py-2.5 font-medium">Period</th><th class="px-4 py-2.5 font-medium">Standard Cost</th><th class="px-4 py-2.5 font-medium">Actual Cost</th><th class="px-4 py-2.5 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($costSheets as $sheet)
              <tr onclick="window.location='{{ route('tan90.brc.costing.show', $sheet->id) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $sheet->code }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $sheet->finishedGood->name ?? '—' }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $sheet->cost_period }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ number_format($sheet->total_standard_cost, 2) }}</td>
                <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $sheet->total_actual_cost !== null ? number_format($sheet->total_actual_cost, 2) : '—' }}</td>
                <td class="px-4 py-2.5">@include('tan90.brc.partials.status-badge', ['value' => $sheet->approval_status])</td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No cost sheets yet. Run a cost roll-up from a finished good's BOM to create one.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $costSheets->links() }}</div>
    </div>
  </div>
</x-app-layout>
