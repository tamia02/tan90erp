@extends('tan90.master-data.layout')

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
        <a class="btn btn-ghost" href="{{ route('tan90.master-data.export', $entity) }}?{{ http_build_query(request()->query()) }}">⇩ Export CSV</a>
      @endcan
      @if (! empty($config['fields']))
        @can('create', $config['model'])
          <a class="btn btn-primary" href="{{ route('tan90.master-data.create', $entity) }}">＋ Add {{ $config['singular'] }}</a>
        @endcan
      @endif
    </div>
  </div>

  <form method="GET" class="toolbar">
    <div class="toolbar-search">
      <span class="search-glyph">⌕</span>
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($config['title']) }}">
    </div>
    <select name="approval_status" onchange="this.form.submit()">
      <option value="">All approvals</option>
      @foreach (['draft', 'review', 'pending', 'approved', 'active', 'rejected'] as $value)
        <option value="{{ $value }}" @selected(request('approval_status') === $value)>{{ ucfirst($value) }}</option>
      @endforeach
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="">Active records</option>
      <option value="archived" @selected(request('status') === 'archived')>Archived</option>
    </select>
    <button class="btn btn-secondary" type="submit">Filter</button>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          @foreach ($config['columns'] as $column)
            <th>{{ Str::title(str_replace(['.', '_'], [' ', ' '], preg_replace('/_id$/', '', $column))) }}</th>
          @endforeach
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($records as $record)
          <tr>
            @foreach ($config['columns'] as $column)
              @php($value = app(\App\Services\Tan90\MasterData\EntityRegistry::class)->columnValue($record, $column))
              <td>
                @if (Str::endsWith($column, ['status', 'gst_status']))
                  @include('tan90.master-data.partials.status-badge', ['value' => $value])
                @elseif ($loop->first)
                  <a href="{{ route('tan90.master-data.show', [$entity, $record->id]) }}" class="cell-main">{{ $value ?? '—' }}</a>
                @else
                  {{ $value ?? '—' }}
                @endif
              </td>
            @endforeach
            <td>
              <div class="row-actions">
                <a class="btn btn-sm btn-ghost" href="{{ route('tan90.master-data.show', [$entity, $record->id]) }}">View</a>
                @can('update', $record)
                  @if (! empty($config['fields']))
                    <a class="btn btn-sm btn-secondary" href="{{ route('tan90.master-data.edit', [$entity, $record->id]) }}">Edit</a>
                  @endif
                @endcan
                @if ($record->status === 'archived')
                  @can('restore', $record)
                    @if (empty($config['no_soft_delete']))
                      <form method="POST" action="{{ route('tan90.master-data.restore', [$entity, $record->id]) }}">
                        @csrf
                        <button class="btn btn-sm btn-success" type="submit">Restore</button>
                      </form>
                    @endif
                  @endcan
                @else
                  @can('delete', $record)
                    <form method="POST" action="{{ route('tan90.master-data.destroy', [$entity, $record->id]) }}" data-confirm="{{ empty($config['no_soft_delete']) ? 'Archive this record?' : 'Delete this record? This cannot be undone.' }}">
                      @csrf @method('DELETE')
                      <button class="btn btn-sm btn-danger" type="submit">{{ empty($config['no_soft_delete']) ? 'Archive' : 'Delete' }}</button>
                    </form>
                  @endcan
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="{{ count($config['columns']) + 1 }}">
            <div class="empty-state"><div><div class="empty-icon">⌕</div><h3>No matching records</h3><p>Try changing the filters or create a new record.</p></div></div>
          </td></tr>
        @endforelse
      </tbody>
    </table>
    <div class="table-footer">
      <span>Showing {{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} of {{ $records->total() }} records</span>
    </div>
  </div>
  <div style="margin-top:12px">{{ $records->links() }}</div>

  <div class="mobile-card-list">
    @foreach ($records as $record)
      <article class="mobile-record">
        <div class="mobile-record-head">
          <div>
            <h4><a href="{{ route('tan90.master-data.show', [$entity, $record->id]) }}">{{ $record->{$config['primary']} ?? '—' }}</a></h4>
            <p class="code">{{ $record->{$config['code']} ?? '' }}</p>
          </div>
          @include('tan90.master-data.partials.status-badge', ['value' => $record->approval_status ?? $record->status])
        </div>
      </article>
    @endforeach
  </div>
@endsection
