<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $bom->code }}</h2>
  </x-slot>

  <div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">BOM / {{ $bom->finishedGood->name ?? '' }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ ucfirst($bom->bom_type) }} BOM</p>
      </div>
      <div class="flex gap-2">
        <a href="{{ route('tan90.brc.boms.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
        @can('update', $bom)
          <form method="POST" action="{{ route('tan90.brc.boms.revisions.store', $bom->id) }}" onsubmit="return confirm('Create a new revision?')">
            @csrf
            <input type="hidden" name="reason" value="Manual revision from BOM detail screen">
            <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">New Revision</button>
          </form>
        @endcan
      </div>
    </div>

    @if ($currentVersion)
      <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
          <div class="text-xs" style="color: var(--text-muted);">Revision</div>
          <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $currentVersion->revision_code }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
          <div class="text-xs" style="color: var(--text-muted);">Gate Status</div>
          <div class="mt-1.5">@include('tan90.brc.partials.status-badge', ['value' => $currentVersion->gate_status])</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
          <div class="text-xs" style="color: var(--text-muted);">Lines</div>
          <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">{{ $currentVersion->lines->count() }}</div>
        </div>
      </div>

      @if ($validation && ! $validation['valid'])
        <div class="rounded-lg border p-3 mb-4 text-sm" style="background: var(--status-critical-bg); border-color: var(--status-critical); color: var(--status-critical);">
          @foreach ($validation['errors'] as $error) <p>{{ $error }}</p> @endforeach
        </div>
      @endif

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
          <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">BOM Lines</h3>
          <p class="text-xs mb-3" style="color: var(--text-muted);">{{ $currentVersion->revision_code }}</p>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                  <th class="py-2 pr-2 font-medium">#</th><th class="py-2 pr-2 font-medium">Type</th><th class="py-2 pr-2 font-medium">Item</th><th class="py-2 pr-2 font-medium">Qty</th><th class="py-2 font-medium">Wastage %</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($currentVersion->lines as $line)
                  <tr style="border-top: 1px solid var(--border);">
                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $line->sequence }}</td>
                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $line->line_type === 'sub_bom' ? 'Sub-BOM' : 'Component' }}</td>
                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $line->line_type === 'sub_bom' ? ($line->subBom->code ?? '—') : ($line->component->name ?? '—') }}</td>
                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $line->quantity }} {{ $line->uom }}</td>
                    <td class="py-2" style="color: var(--text-primary);">{{ $line->wastage_percent }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @can('update', $bom)
            @if ($currentVersion->gate_status === 'draft')
              <form method="POST" action="{{ route('tan90.brc.bom-versions.lines.store', $currentVersion->id) }}" class="grid grid-cols-2 gap-2 mt-4">
                @csrf
                <label class="flex flex-col gap-1 text-xs">
                  <span class="font-medium" style="color: var(--text-primary);">Line Type</span>
                  <select name="line_type" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
                    <option value="component">Component</option>
                    <option value="sub_bom">Sub-BOM</option>
                  </select>
                </label>
                <label class="flex flex-col gap-1 text-xs">
                  <span class="font-medium" style="color: var(--text-primary);">Component</span>
                  <select name="tan90_component_id" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">
                    <option value="">—</option>
                    @foreach (\App\Models\Tan90\BomRecipeCosting\Component::active()->orderBy('name')->get() as $component)
                      <option value="{{ $component->id }}">{{ $component->name }}</option>
                    @endforeach
                  </select>
                </label>
                <label class="flex flex-col gap-1 text-xs col-span-2">
                  <span class="font-medium" style="color: var(--text-primary);">Sub-BOM</span>
                  <select name="tan90_sub_bom_id" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">
                    <option value="">—</option>
                    @foreach (\App\Models\Tan90\BomRecipeCosting\Bom::where('id', '!=', $bom->id)->get() as $otherBom)
                      <option value="{{ $otherBom->id }}">{{ $otherBom->code }}</option>
                    @endforeach
                  </select>
                </label>
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">Quantity</span><input type="number" step="0.0001" name="quantity" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required></label>
                <label class="flex flex-col gap-1 text-xs"><span class="font-medium" style="color: var(--text-primary);">UOM</span><input type="text" name="uom" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
                <label class="flex flex-col gap-1 text-xs col-span-2"><span class="font-medium" style="color: var(--text-primary);">Wastage %</span><input type="number" step="0.01" name="wastage_percent" value="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);"></label>
                <button type="submit" class="col-span-2 rounded-lg px-3 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Add Line</button>
              </form>
            @endif
          @endcan
        </div>

        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
          <h3 class="font-semibold text-sm mb-1" style="color: var(--text-primary);">Release Gates</h3>
          <p class="text-xs mb-3" style="color: var(--text-muted);">P0 workflow</p>
          <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @forelse ($gateHistory as $gate)
              <div class="py-2.5">
                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate }} · {{ $gate->status }}</div>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $gate->reviewed_at?->format('d M Y, H:i') }} — {{ $gate->reviewedBy?->name }}</div>
              </div>
            @empty
              <p class="text-sm py-4" style="color: var(--text-muted);">No gates passed yet.</p>
            @endforelse
          </div>

          @can('approve', $bom)
            <form method="POST" action="{{ route('tan90.brc.bom-versions.gates.pass', $currentVersion->id) }}" class="grid gap-2 mt-4">
              @csrf
              <label class="flex flex-col gap-1 text-xs">
                <span class="font-medium" style="color: var(--text-primary);">Pass Gate</span>
                <select name="gate" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);" required>
                  @foreach (['Draft', 'Technical Review', 'QA Review', 'Cost Review', 'Plant Trial', 'Release', 'MRP Ready'] as $gate)
                    <option value="{{ $gate }}">{{ $gate }}</option>
                  @endforeach
                </select>
              </label>
              <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-white" style="background: var(--brand);">Pass Gate</button>
            </form>
          @endcan
        </div>
      </div>

      <div class="rounded-lg border p-4 mt-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Where Used</h3>
        <p class="text-xs -mt-2 mb-3" style="color: var(--text-muted);">Parent BOMs referencing this BOM as a sub-BOM</p>
        @forelse ($usedIn as $line)
          <div class="py-2" style="border-top: 1px solid var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $line->bomVersion->bom->code ?? '—' }}</div>
            <div class="text-xs" style="color: var(--text-muted);">Line #{{ $line->sequence }}</div>
          </div>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">Not used in any other BOM.</p>
        @endforelse
      </div>
    @else
      <p class="text-sm" style="color: var(--text-muted);">No revision yet.</p>
    @endif
  </div>
</x-app-layout>
