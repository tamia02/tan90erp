@extends('tan90.brc.layout')

@section('title', $recipe->code)
@section('page-title', $recipe->name)
@section('page-subtitle', $recipe->code)

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Recipe / {{ $recipe->finishedGood->name ?? '' }}</p>
      <h2>{{ $recipe->name }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.brc.recipes.index') }}">← Back</a>
      @can('update', $recipe)
        <form method="POST" action="{{ route('tan90.brc.recipes.revisions.store', $recipe->id) }}" onsubmit="return confirm('Create a new revision?')">
          @csrf
          <input type="hidden" name="reason" value="Manual revision from recipe detail screen">
          <button class="btn btn-secondary" type="submit">New Revision</button>
        </form>
      @endcan
    </div>
  </div>

  @if ($currentVersion)
    <section class="kpi-grid compact">
      <div class="kpi-card"><span>Revision</span><strong>{{ $currentVersion->revision_code }}</strong></div>
      <div class="kpi-card"><span>Gate Status</span><strong>@include('tan90.brc.partials.status-badge', ['value' => $currentVersion->gate_status])</strong></div>
      <div class="kpi-card"><span>Formula Total</span><strong>{{ $validation['total'] ?? 0 }}%</strong></div>
    </section>

    @if ($validation && ! $validation['valid'])
      <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--danger)">
        @foreach ($validation['errors'] as $error) <p>{{ $error }}</p> @endforeach
      </div>
    @endif

    <section class="grid grid-2">
      <article class="card">
        <div class="card-head"><div><h3>Component Lines</h3><p>{{ $currentVersion->revision_code }}</p></div></div>
        <div class="card-body">
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>#</th><th>Component</th><th>%</th><th>Wastage %</th><th>UOM</th></tr></thead>
              <tbody>
                @foreach ($currentVersion->lines as $line)
                  <tr>
                    <td>{{ $line->sequence }}</td>
                    <td>{{ $line->component->name ?? '—' }}{{ $line->is_alternate ? ' (Alt)' : '' }}</td>
                    <td>{{ $line->percentage }}</td>
                    <td>{{ $line->wastage_percent }}</td>
                    <td>{{ $line->uom }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          @can('update', $recipe)
            @if ($currentVersion->gate_status === 'draft')
              <form method="POST" action="{{ route('tan90.brc.recipe-versions.lines.store', $currentVersion->id) }}" class="form-grid" style="margin-top:14px">
                @csrf
                <label class="field">
                  <span class="field-label">Component</span>
                  <select name="tan90_component_id" required>
                    @foreach (\App\Models\Tan90\BomRecipeCosting\Component::active()->orderBy('name')->get() as $component)
                      <option value="{{ $component->id }}">{{ $component->name }}</option>
                    @endforeach
                  </select>
                </label>
                <label class="field"><span class="field-label">%</span><input type="number" step="0.0001" name="percentage" required></label>
                <label class="field"><span class="field-label">Wastage %</span><input type="number" step="0.01" name="wastage_percent" value="0"></label>
                <label class="field"><span class="field-label">UOM</span><input type="text" name="uom"></label>
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

          @can('approve', $recipe)
            <form method="POST" action="{{ route('tan90.brc.recipe-versions.gates.pass', $currentVersion->id) }}" class="form-grid" style="margin-top:14px">
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
  @else
    <div class="empty-state"><div><div class="empty-icon">RC</div><h3>No revision yet</h3></div></div>
  @endif
@endsection
