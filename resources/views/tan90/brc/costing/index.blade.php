@extends('tan90.brc.layout')

@section('title', 'Cost Sheets')
@section('page-title', 'Cost Sheets')
@section('page-subtitle', $costSheets->total() . ' cost sheets')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Costing</p>
      <h2>Cost Sheets</h2>
      <p>Standard cost per finished good and cost period, rolled up from BOM + routing rates.</p>
    </div>
  </div>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Finished Good</th><th>Period</th><th>Standard Cost</th><th>Actual Cost</th><th>Status</th></tr></thead>
        <tbody>
          @forelse ($costSheets as $sheet)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.costing.show', $sheet->id) }}'">
              <td>{{ $sheet->code }}</td>
              <td>{{ $sheet->finishedGood->name ?? '—' }}</td>
              <td>{{ $sheet->cost_period }}</td>
              <td>{{ number_format($sheet->total_standard_cost, 2) }}</td>
              <td>{{ $sheet->total_actual_cost !== null ? number_format($sheet->total_actual_cost, 2) : '—' }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $sheet->approval_status])</td>
            </tr>
          @empty
            <tr><td colspan="6"><div class="empty-state"><div><div class="empty-icon">CS</div><h3>No cost sheets yet</h3><p>Run a cost roll-up from a finished good's BOM to create one.</p></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $costSheets->links() }}</div>
  </section>
@endsection
