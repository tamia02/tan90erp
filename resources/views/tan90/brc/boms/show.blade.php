@extends('tan90.brc.layout')

@section('title', $bom->code)
@section('page-title', $bom->code)
@section('page-subtitle', ucfirst($bom->bom_type) . ' BOM')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">BOM / {{ $bom->finishedGood->name ?? '' }}</p>
      <h2>{{ $bom->code }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.brc.boms.index') }}">← Back</a>
      @can('update', $bom)
        <form method="POST" action="{{ route('tan90.brc.boms.revisions.store', $bom->id) }}" onsubmit="return confirm('Create a new revision?')">
          @csrf
          <input type="hidden" name="reason" value="Manual revision from BOM detail screen">
          <button class="btn btn-secondary" type="submit">New Revision</button>
        </form>
      @endcan
    </div>
  </div>

  @if ($currentVersion)
    <section class="kpi-grid compact">
      <div class="kpi-card"><span>Revision</span><strong>{{ $currentVersion->revision_code }}</strong></div>
      <div class="kpi-card"><span>Gate Status</span><strong>@include('tan90.brc.partials.status-badge', ['value' => $currentVersion->gate_status])</strong></div>
      <div class="kpi-card"><span>Lines</span><strong>{{ $currentVersion->lines->count() }}</strong></div>
    </section>

    @if ($validation && ! $validation['valid'])
      <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--danger)">
        @foreach ($validation['errors'] as $error) <p>{{ $error }}</p> @endforeach
      </div>
    @endif

    <section class="grid grid-2">
      <article class="card">
        <div class="card-head"><div><h3>BOM Lines</h3><p>{{ $currentVersion->revision_code }}</p></div></div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>#</th><th>Type</th><th>Item</th><th>Qty</th><th>Wastage %</th></tr></thead>
              <tbody>
                @foreach ($currentVersion->lines as $line)
                  <tr>
                    <td>{{ $line->sequence }}</td>
                    <td>{{ $line->line_type === 'sub_bom' ? 'Sub-BOM' : 'Component' }}</td>
                    <td>{{ $line->line_type === 'sub_bom' ? ($line->subBom->code ?? '—') : ($line->component->name ?? '—') }}</td>
                    <td>{{ $line->quantity }} {{ $line->uom }}</td>
                    <td>{{ $line->wastage_percent }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @can('update', $bom)
            @if ($currentVersion->gate_status === 'draft')
              <form method="POST" action="{{ route('tan90.brc.bom-versions.lines.store', $currentVersion->id) }}" class="form-grid" style="margin-top:14px">
                @csrf
                <label class="field">
                  <span class="field-label">Line Type</span>
                  <select name="line_type" required>
                    <option value="component">Component</option>
                    <option value="sub_bom">Sub-BOM</option>
                  </select>
                </label>
                <label class="field">
                  <span class="field-label">Component</span>
                  <select name="tan90_component_id">
                    <option value="">—</option>
                    @foreach (\App\Models\Tan90\BomRecipeCosting\Component::active()->orderBy('name')->get() as $component)
                      <option value="{{ $component->id }}">{{ $component->name }}</option>
                    @endforeach
                  </select>
                </label>
                <label class="field">
                  <span class="field-label">Sub-BOM</span>
                  <select name="tan90_sub_bom_id">
                    <option value="">—</option>
                    @foreach (\App\Models\Tan90\BomRecipeCosting\Bom::where('id', '!=', $bom->id)->get() as $otherBom)
                      <option value="{{ $otherBom->id }}">{{ $otherBom->code }}</option>
                    @endforeach
                  </select>
                </label>
                <label class="field"><span class="field-label">Quantity</span><input type="number" step="0.0001" name="quantity" required></label>
                <label class="field"><span class="field-label">UOM</span><input type="text" name="uom"></label>
                <label class="field"><span class="field-label">Wastage %</span><input type="number" step="0.01" name="wastage_percent" value="0"></label>
                <div class="full"><button class="btn btn-secondary" type="submit">Add Line</button></div>
              </form>
            @endif
          @endcan
        </div>
      </article>

      <article class="card">
        <div class="card-head"><div><h3>Release Gates</h3><p>P0 workflow</p></div></div>
        <div class="card-body">
          <div class="timeline">
            @forelse ($gateHistory as $gate)
              <div class="timeline-item">
                <span class="timeline-dot"></span>
                <h4>{{ $gate->gate }} · {{ $gate->status }}</h4>
                <p>{{ $gate->reviewed_at?->format('d M Y, H:i') }} — {{ $gate->reviewedBy?->name }}</p>
              </div>
            @empty
              <div class="empty-state"><div><div class="empty-icon">RG</div><h3>No gates passed yet</h3></div></div>
            @endforelse
          </div>

          @can('approve', $bom)
            <form method="POST" action="{{ route('tan90.brc.bom-versions.gates.pass', $currentVersion->id) }}" class="form-grid" style="margin-top:14px">
              @csrf
              <label class="field">
                <span class="field-label">Pass Gate</span>
                <select name="gate" required>
                  @foreach (['Draft', 'Technical Review', 'QA Review', 'Cost Review', 'Plant Trial', 'Release', 'MRP Ready'] as $gate)
                    <option value="{{ $gate }}">{{ $gate }}</option>
                  @endforeach
                </select>
              </label>
              <div class="full"><button class="btn btn-primary" type="submit">Pass Gate</button></div>
            </form>
          @endcan
        </div>
      </article>
    </section>

    <section class="card" style="margin-top:15px">
      <div class="card-head"><div><h3>Where Used</h3><p>Parent BOMs referencing this BOM as a sub-BOM</p></div></div>
      <div class="card-body">
        @forelse ($usedIn as $line)
          <div class="mini-row"><div><strong>{{ $line->bomVersion->bom->code ?? '—' }}</strong><span>Line #{{ $line->sequence }}</span></div></div>
        @empty
          <div class="empty-state"><div><div class="empty-icon">WU</div><h3>Not used in any other BOM</h3></div></div>
        @endforelse
      </div>
    </section>
  @else
    <div class="empty-state"><div><div class="empty-icon">BM</div><h3>No revision yet</h3></div></div>
  @endif
@endsection
