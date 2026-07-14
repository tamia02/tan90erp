@extends('tan90.brc.layout')

@section('title', $routing->code)
@section('page-title', $routing->name)
@section('page-subtitle', $routing->code)

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Routing / {{ $routing->finishedGood->name ?? '' }}</p>
      <h2>{{ $routing->name }}</h2>
    </div>
    <div class="page-actions"><a class="btn btn-ghost" href="{{ route('tan90.brc.routings.index') }}">← Back</a></div>
  </div>

  <section class="card">
    <div class="card-head"><div><h3>Operations</h3><p>Sequenced work centers, setup/run time</p></div></div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>#</th><th>Operation</th><th>Work Center</th><th>Setup (min)</th><th>Run (min)</th><th></th></tr></thead>
          <tbody>
            @forelse ($routing->operations as $operation)
              <tr>
                <td>{{ $operation->sequence }}</td>
                <td>{{ $operation->operation_name }}</td>
                <td>{{ $operation->workCenter->name ?? '—' }}</td>
                <td>{{ $operation->setup_time_minutes }}</td>
                <td>{{ $operation->run_time_minutes }}</td>
                <td>
                  @can('update', $routing)
                    <form method="POST" action="{{ route('tan90.brc.routings.operations.destroy', [$routing->id, $operation->id]) }}" data-confirm="Remove this operation?">
                      @csrf @method('DELETE')
                      <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                    </form>
                  @endcan
                </td>
              </tr>
            @empty
              <tr><td colspan="6"><div class="empty-state"><div><div class="empty-icon">OP</div><h3>No operations yet</h3></div></div></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @can('update', $routing)
        <form method="POST" action="{{ route('tan90.brc.routings.operations.store', $routing->id) }}" class="form-grid" style="margin-top:14px">
          @csrf
          <label class="field"><span class="field-label">Operation Name</span><input type="text" name="operation_name" required></label>
          <label class="field">
            <span class="field-label">Work Center</span>
            <select name="tan90_work_center_id" required>
              @foreach (\App\Models\Tan90\BomRecipeCosting\WorkCenter::active()->orderBy('name')->get() as $wc)
                <option value="{{ $wc->id }}">{{ $wc->name }}</option>
              @endforeach
            </select>
          </label>
          <label class="field"><span class="field-label">Setup Time (min)</span><input type="number" step="0.01" name="setup_time_minutes" value="0"></label>
          <label class="field"><span class="field-label">Run Time (min)</span><input type="number" step="0.01" name="run_time_minutes" value="0"></label>
          <div class="full"><button class="btn btn-secondary" type="submit">Add Operation</button></div>
        </form>
      @endcan
    </div>
  </section>
@endsection
