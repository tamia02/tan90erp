<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $routing->name }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Routing / {{ $routing->finishedGood->name ?? '' }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $routing->code }}</p>
      </div>
      <a href="{{ route('tan90.brc.routings.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
      <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">Operations</h3>
      <p class="text-xs mb-3" style="color: var(--text-muted);">Sequenced work centers, setup/run time</p>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              <th class="py-2 pr-2 font-medium">#</th><th class="py-2 pr-2 font-medium">Operation</th><th class="py-2 pr-2 font-medium">Work Center</th><th class="py-2 pr-2 font-medium">Setup (min)</th><th class="py-2 pr-2 font-medium">Run (min)</th><th class="py-2 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($routing->operations as $operation)
              <tr style="border-top: 1px solid var(--border);">
                <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $operation->sequence }}</td>
                <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $operation->operation_name }}</td>
                <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $operation->workCenter->name ?? '—' }}</td>
                <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $operation->setup_time_minutes }}</td>
                <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $operation->run_time_minutes }}</td>
                <td class="py-2">
                  @can('update', $routing)
                    <form method="POST" action="{{ route('tan90.brc.routings.operations.destroy', [$routing->id, $operation->id]) }}" onsubmit="return confirm('Remove this operation?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="text-xs font-medium" style="color: var(--status-critical);">Remove</button>
                    </form>
                  @endcan
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="py-10 text-center text-sm" style="color: var(--text-muted);">No operations yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @can('update', $routing)
        <form method="POST" action="{{ route('tan90.brc.routings.operations.store', $routing->id) }}" class="grid grid-cols-2 gap-2 mt-4">
          @csrf
          <label class="flex flex-col gap-1 text-xs col-span-2"><span class="font-medium" style="color: var(--text-primary);">Operation Name</span><input type="text" name="operation_name" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required></label>
          <label class="flex flex-col gap-1 text-xs col-span-2">
            <span class="font-medium" style="color: var(--text-primary);">Work Center</span>
            <select name="tan90_work_center_id" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
              @foreach (\App\Models\Tan90\BomRecipeCosting\WorkCenter::active()->orderBy('name')->get() as $wc)
                <option value="{{ $wc->id }}">{{ $wc->name }}</option>
              @endforeach
            </select>
          </label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Setup Time (min)</span><input type="number" step="0.01" name="setup_time_minutes" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Run Time (min)</span><input type="number" step="0.01" name="run_time_minutes" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
          <button type="submit" class="col-span-2 rounded-lg px-3 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Add Operation</button>
        </form>
      @endcan
    </div>
  </div>
</x-app-layout>
