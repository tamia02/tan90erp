@extends('tan90.brc.layout')

@section('title', $config['title'])
@section('page-title', $config['title'])
@section('page-subtitle', $records->total() . ' records')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $config['eyebrow'] }}</p>
      <h2>{{ $config['title'] }}</h2>
      <p>{{ $config['description'] }}</p>
    </div>
    <div class="page-actions">
      @can('export', $config['model'])
        <a class="btn btn-ghost" href="{{ route('tan90.brc.export', $entity) }}?{{ http_build_query(request()->query()) }}">⇩ Export CSV</a>
      @endcan
      @can('create', $config['model'])
        <a class="btn btn-primary" href="{{ route('tan90.brc.create', $entity) }}">＋ Add {{ $config['singular'] }}</a>
      @endcan
    </div>
  </div>

  <form method="GET" class="toolbar">
    <div class="toolbar-search">
      <span class="search-glyph">⌕</span>
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($config['title']) }}">
    </div>
    <select name="approval_status" onchange="this.form.submit()">
      <option value="">All approvals</option>
      @foreach (['draft', 'review', 'approved', 'rejected'] as $value)
        <option value="{{ $value }}" @selected(request('approval_status') === $value)>{{ ucfirst($value) }}</option>
      @endforeach
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="">Active records</option>
      <option value="archived" @selected(request('status') === 'archived')>Archived</option>
    </select>
  </form>

  <section class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            @foreach ($config['columns'] as $column)
              <th>{{ Str::title(str_replace('_', ' ', preg_replace('/\.(name|code)$/', '', $column))) }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse ($records as $record)
            <tr class="record-row" onclick="window.location='{{ route('tan90.brc.show', [$entity, $record->id]) }}'">
              @foreach ($config['columns'] as $column)
                <td>
                  @if (str_ends_with($column, 'approval_status'))
                    @include('tan90.brc.partials.status-badge', ['value' => $record->approval_status])
                  @elseif ($column === 'status')
                    @include('tan90.brc.partials.status-badge', ['value' => $record->status])
                  @else
                    {{ app(\App\Services\Tan90\BomRecipeCosting\EntityRegistry::class)->columnValue($record, $column) }}
                  @endif
                </td>
              @endforeach
            </tr>
          @empty
            <tr><td colspan="{{ count($config['columns']) }}">
              <div class="empty-state"><div><div class="empty-icon">{{ $config['icon'] }}</div><h3>No {{ strtolower($config['title']) }} yet</h3><p>Add the first record to get started.</p></div></div>
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-foot">{{ $records->links() }}</div>
  </section>
@endsection
