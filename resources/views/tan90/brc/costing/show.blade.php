<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $costSheet->code }}</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Cost Sheet / {{ $costSheet->finishedGood->name ?? '' }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $costSheet->cost_period }}</p>
      </div>
      <div class="flex gap-2">
        <a href="{{ route('tan90.brc.costing.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
        @can('approve', $costSheet)
          @if ($costSheet->approval_status !== 'approved')
            <form method="POST" action="{{ route('tan90.brc.costing.approve-standard', $costSheet->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Approve Standard Cost</button>
            </form>
          @endif
        @endcan
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Material</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->material_cost, 2) }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Labor</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->labor_cost, 2) }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Machine</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->machine_cost, 2) }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Utility</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->utility_cost, 2) }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Overhead</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->overhead_cost, 2) }}</div></div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);"><div class="text-xs" style="color: var(--text-muted);">Total Standard</div><div class="text-lg font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($costSheet->total_standard_cost, 2) }}</div></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">Record Actual Cost</h3>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Compares against the approved standard, writes variance</p>
        @can('update', $costSheet)
          <form method="POST" action="{{ route('tan90.brc.costing.actual.store', $costSheet->id) }}" class="grid grid-cols-2 gap-2">
            @csrf
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Material</span><input type="number" step="0.01" name="material" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Labor</span><input type="number" step="0.01" name="labor" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Machine</span><input type="number" step="0.01" name="machine" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
            <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Utility</span><input type="number" step="0.01" name="utility" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
            <label class="flex flex-col gap-1 text-xs col-span-2"><span class="font-medium" style="color: var(--text-primary);">Overhead</span><input type="number" step="0.01" name="overhead" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
            <button type="submit" class="col-span-2 rounded-lg px-3 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Record Actual</button>
          </form>
        @endcan

        <div class="overflow-x-auto mt-4">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                <th class="py-2 pr-2 font-medium">Type</th><th class="py-2 pr-2 font-medium">Standard</th><th class="py-2 pr-2 font-medium">Actual</th><th class="py-2 pr-2 font-medium">Variance</th><th class="py-2 font-medium">%</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($costSheet->variances as $variance)
                <tr style="border-top: 1px solid var(--border);">
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ ucfirst($variance->variance_type) }}</td>
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ number_format($variance->standard_cost, 2) }}</td>
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ number_format($variance->actual_cost, 2) }}</td>
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ number_format($variance->variance_amount, 2) }}</td>
                  <td class="py-2" style="color: var(--text-primary);">{{ $variance->variance_percent }}%</td>
                </tr>
              @empty
                <tr><td colspan="5" class="py-10 text-center text-sm" style="color: var(--text-muted);">No variance recorded yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">Cost Simulation</h3>
        <p class="text-xs mb-3" style="color: var(--text-muted);">What-if adjustments, not persisted to the standard</p>
        <form method="POST" action="{{ route('tan90.brc.costing.simulate', $costSheet->id) }}" class="grid grid-cols-2 gap-2">
          @csrf
          <label class="flex flex-col gap-1 text-xs col-span-2"><span class="font-medium" style="color: var(--text-primary);">Scenario Name</span><input type="text" name="scenario_name" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Material % change</span><input type="number" step="0.1" name="adjustments[material]" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Labor % change</span><input type="number" step="0.1" name="adjustments[labor]" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Machine % change</span><input type="number" step="0.1" name="adjustments[machine]" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Utility % change</span><input type="number" step="0.1" name="adjustments[utility]" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Overhead % change</span><input type="number" step="0.1" name="adjustments[overhead]" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Selling Price (Optional)</span><input type="number" step="0.01" name="selling_price" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <button type="submit" class="col-span-2 rounded-lg px-3 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Run Simulation</button>
        </form>

        <div class="overflow-x-auto mt-4">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                <th class="py-2 pr-2 font-medium">Scenario</th><th class="py-2 pr-2 font-medium">Simulated Total</th><th class="py-2 font-medium">Margin %</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($costSheet->simulations as $simulation)
                <tr style="border-top: 1px solid var(--border);">
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $simulation->scenario_name }}</td>
                  <td class="py-2 pr-2" style="color: var(--text-primary);">{{ number_format($simulation->simulated_total_cost, 2) }}</td>
                  <td class="py-2" style="color: var(--text-primary);">{{ $simulation->margin_percent !== null ? $simulation->margin_percent . '%' : '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="3" class="py-10 text-center text-sm" style="color: var(--text-muted);">No simulations yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</x-app-layout>
