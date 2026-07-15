<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $config['title'] }}</h2>
  </x-slot>

  <div class="max-w-6xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">{{ $config['eyebrow'] }}</p>
        <h1 class="text-xl sm:text-2xl font-semibold mt-0.5" style="color: var(--text-primary);">{{ $config['title'] }}</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $config['description'] }}</p>
      </div>
      <div class="flex gap-2">
        @can('export', $config['model'])
          <a href="{{ route('tan90.master-data.export', $entity) }}?{{ http_build_query(request()->query()) }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Export CSV</a>
        @endcan
        @if (! empty($config['fields']))
          @can('create', $config['model'])
            <a href="{{ route('tan90.master-data.create', $entity) }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">+ Add {{ $config['singular'] }}</a>
          @endcan
        @endif
      </div>
    </div>

    <form method="GET" class="flex flex-col sm:flex-row gap-2 mb-4">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($config['title']) }}" class="flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
      <select name="approval_status" onchange="this.form.submit()" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">All approvals</option>
        @foreach (['draft', 'review', 'pending', 'approved', 'active', 'rejected'] as $value)
          <option value="{{ $value }}" @selected(request('approval_status') === $value)>{{ ucfirst($value) }}</option>
        @endforeach
      </select>
      <select name="status" onchange="this.form.submit()" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">Active records</option>
        <option value="archived" @selected(request('status') === 'archived')>Archived</option>
      </select>
    </form>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
              @foreach ($config['columns'] as $column)
                <th class="px-4 py-2.5 font-medium">{{ Str::title(str_replace(['.', '_'], [' ', ' '], preg_replace('/_id$/', '', $column))) }}</th>
              @endforeach
              <th class="px-4 py-2.5 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($records as $record)
              <tr style="border-top: 1px solid var(--border);">
                @foreach ($config['columns'] as $column)
                  @php($value = app(\App\Services\Tan90\MasterData\EntityRegistry::class)->columnValue($record, $column))
                  <td class="px-4 py-2.5" style="color: var(--text-primary);">
                    @if (Str::endsWith($column, ['status', 'gst_status']))
                      @include('tan90.master-data.partials.status-badge', ['value' => $value])
                    @elseif ($loop->first)
                      <a href="{{ route('tan90.master-data.show', [$entity, $record->id]) }}" class="font-medium" style="color: var(--brand);">{{ $value ?? '—' }}</a>
                    @else
                      {{ $value ?? '—' }}
                    @endif
                  </td>
                @endforeach
                <td class="px-4 py-2.5 text-right">
                  <div class="flex items-center justify-end gap-2 text-xs font-medium">
                    <a href="{{ route('tan90.master-data.show', [$entity, $record->id]) }}" style="color: var(--brand);">View</a>
                    @can('update', $record)
                      @if (! empty($config['fields']))
                        <a href="{{ route('tan90.master-data.edit', [$entity, $record->id]) }}" style="color: var(--text-secondary);">Edit</a>
                      @endif
                    @endcan
                    @if ($record->status === 'archived')
                      @can('restore', $record)
                        @if (empty($config['no_soft_delete']))
                          <form method="POST" action="{{ route('tan90.master-data.restore', [$entity, $record->id]) }}">
                            @csrf
                            <button type="submit" style="color: var(--status-good);">Restore</button>
                          </form>
                        @endif
                      @endcan
                    @else
                      @can('delete', $record)
                        <form method="POST" action="{{ route('tan90.master-data.destroy', [$entity, $record->id]) }}" onsubmit="return confirm('{{ empty($config['no_soft_delete']) ? 'Archive this record?' : 'Delete this record? This cannot be undone.' }}')">
                          @csrf @method('DELETE')
                          <button type="submit" style="color: var(--status-critical);">{{ empty($config['no_soft_delete']) ? 'Archive' : 'Delete' }}</button>
                        </form>
                      @endcan
                    @endif
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="{{ count($config['columns']) + 1 }}" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No matching records. Try changing the filters or create a new record.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 flex items-center justify-between text-xs" style="border-top: 1px solid var(--border); color: var(--text-muted);">
        <span>Showing {{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }} of {{ $records->total() }} records</span>
      </div>
      <div class="px-4 pb-3">{{ $records->links() }}</div>
    </div>
  </div>
</x-app-layout>
