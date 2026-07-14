@extends('tan90.brc.layout')

@section('title', $costSheet->code)
@section('page-title', $costSheet->code)
@section('page-subtitle', $costSheet->cost_period)

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Cost Sheet / {{ $costSheet->finishedGood->name ?? '' }}</p>
      <h2>{{ $costSheet->code }}</h2>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.brc.costing.index') }}">← Back</a>
      @can('approve', $costSheet)
        @if ($costSheet->approval_status !== 'approved')
          <form method="POST" action="{{ route('tan90.brc.costing.approve-standard', $costSheet->id) }}">
            @csrf <button class="btn btn-success" type="submit">Approve Standard Cost</button>
          </form>
        @endif
      @endcan
    </div>
  </div>

  <section class="kpi-grid compact">
    <div class="kpi-card"><span>Material</span><strong>{{ number_format($costSheet->material_cost, 2) }}</strong></div>
    <div class="kpi-card"><span>Labor</span><strong>{{ number_format($costSheet->labor_cost, 2) }}</strong></div>
    <div class="kpi-card"><span>Machine</span><strong>{{ number_format($costSheet->machine_cost, 2) }}</strong></div>
    <div class="kpi-card"><span>Utility</span><strong>{{ number_format($costSheet->utility_cost, 2) }}</strong></div>
    <div class="kpi-card"><span>Overhead</span><strong>{{ number_format($costSheet->overhead_cost, 2) }}</strong></div>
    <div class="kpi-card"><span>Total Standard</span><strong>{{ number_format($costSheet->total_standard_cost, 2) }}</strong></div>
  </section>

  <section class="grid grid-2" style="margin-top:15px">
    <article class="card">
      <div class="card-head"><div><h3>Record Actual Cost</h3><p>Compares against the approved standard, writes variance</p></div></div>
      <div class="card-body">
        @can('update', $costSheet)
          <form method="POST" action="{{ route('tan90.brc.costing.actual.store', $costSheet->id) }}" class="form-grid">
            @csrf
            <label class="field"><span class="field-label">Material</span><input type="number" step="0.01" name="material"></label>
            <label class="field"><span class="field-label">Labor</span><input type="number" step="0.01" name="labor"></label>
            <label class="field"><span class="field-label">Machine</span><input type="number" step="0.01" name="machine"></label>
            <label class="field"><span class="field-label">Utility</span><input type="number" step="0.01" name="utility"></label>
            <label class="field"><span class="field-label">Overhead</span><input type="number" step="0.01" name="overhead"></label>
            <div class="full"><button class="btn btn-secondary" type="submit">Record Actual</button></div>
          </form>
        @endcan

        <div class="table-wrap" style="margin-top:14px">
          <table class="data-table">
            <thead><tr><th>Type</th><th>Standard</th><th>Actual</th><th>Variance</th><th>%</th></tr></thead>
            <tbody>
              @forelse ($costSheet->variances as $variance)
                <tr>
                  <td>{{ ucfirst($variance->variance_type) }}</td>
                  <td>{{ number_format($variance->standard_cost, 2) }}</td>
                  <td>{{ number_format($variance->actual_cost, 2) }}</td>
                  <td>{{ number_format($variance->variance_amount, 2) }}</td>
                  <td>{{ $variance->variance_percent }}%</td>
                </tr>
              @empty
                <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">VR</div><h3>No variance recorded yet</h3></div></div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </article>

    <article class="card">
      <div class="card-head"><div><h3>Cost Simulation</h3><p>What-if adjustments, not persisted to the standard</p></div></div>
      <div class="card-body">
        <form method="POST" action="{{ route('tan90.brc.costing.simulate', $costSheet->id) }}" class="form-grid">
          @csrf
          <label class="field full"><span class="field-label">Scenario Name</span><input type="text" name="scenario_name" required></label>
          <label class="field"><span class="field-label">Material % change</span><input type="number" step="0.1" name="adjustments[material]" value="0"></label>
          <label class="field"><span class="field-label">Labor % change</span><input type="number" step="0.1" name="adjustments[labor]" value="0"></label>
          <label class="field"><span class="field-label">Machine % change</span><input type="number" step="0.1" name="adjustments[machine]" value="0"></label>
          <label class="field"><span class="field-label">Utility % change</span><input type="number" step="0.1" name="adjustments[utility]" value="0"></label>
          <label class="field"><span class="field-label">Overhead % change</span><input type="number" step="0.1" name="adjustments[overhead]" value="0"></label>
          <label class="field"><span class="field-label">Selling Price (Optional)</span><input type="number" step="0.01" name="selling_price"></label>
          <div class="full"><button class="btn btn-secondary" type="submit">Run Simulation</button></div>
        </form>

        <div class="table-wrap" style="margin-top:14px">
          <table class="data-table">
            <thead><tr><th>Scenario</th><th>Simulated Total</th><th>Margin %</th></tr></thead>
            <tbody>
              @forelse ($costSheet->simulations as $simulation)
                <tr>
                  <td>{{ $simulation->scenario_name }}</td>
                  <td>{{ number_format($simulation->simulated_total_cost, 2) }}</td>
                  <td>{{ $simulation->margin_percent !== null ? $simulation->margin_percent . '%' : '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="3"><div class="empty-state"><div><div class="empty-icon">SM</div><h3>No simulations yet</h3></div></div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </article>
  </section>
@endsection
