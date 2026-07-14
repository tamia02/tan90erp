@extends('tan90.brc.layout')

@section('title', 'BOM Register')
@section('page-title', 'BOM Register')
@section('page-subtitle', $boms->total() . ' BOMs')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">Manufacturing</p>
      <h2>BOM Register</h2>
      <p>Production, packaging and service BOMs with revisioned lines.</p>
    </div>
    <div class="page-actions">
      @can('create', \App\Models\Tan90\BomRecipeCosting\Bom::class)
        <a class="btn btn-primary" href="{{ route('tan90.brc.boms.create') }}">＋ New BOM</a>
      @endcan
    </div>
  </div>

  <form method="GET" class="toolbar">
    <div class="toolbar-search">
      <span class="search-glyph">⌕</span>
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search BOMs">
    </div>
    <select name="bom_type" onchange="this.form.submit()">
      <option value="">All types</option>
      @foreach (['production', 'packaging', 'service'] as $type)
        <option value="{{ $type }}" @selected(request('bom_type') === $type)>{{ ucfirst($type) }}</option>
      @endforeach
    </select>
  </form>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Code</th><th>Finished Good</th><th>Type</th><th>Current Revision</th><th>Gate Status</th></tr></thead>
        <tbody>
          @forelse ($boms as $bom)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.boms.show', $bom->id) }}'">
              <td>{{ $bom->code }}</td>
              <td>{{ $bom->finishedGood->name ?? '—' }}</td>
              <td>{{ ucfirst($bom->bom_type) }}</td>
              <td>{{ $bom->currentVersion?->revision_code ?? '—' }}</td>
              <td>@include('tan90.brc.partials.status-badge', ['value' => $bom->currentVersion?->gate_status])</td>
            </tr>
          @empty
            <tr><td colspan="5"><div class="empty-state"><div><div class="empty-icon">BM</div><h3>No BOMs yet</h3></div></div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $boms->links() }}</div>
  </section>
@endsection
