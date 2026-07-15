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
          <a href="{{ route('tan90.brc.export', $entity) }}?{{ http_build_query(request()->query()) }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Export CSV</a>
        @endcan
        @can('create', $config['model'])
          <a href="{{ route('tan90.brc.create', $entity) }}" class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">+ Add {{ $config['singular'] }}</a>
        @endcan
      </div>
    </div>

    <form method="GET" class="flex flex-col sm:flex-row gap-2 mb-4">
      <input type="text" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($config['title']) }}" class="flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
      <select name="approval_status" onchange="this.form.submit()" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
        <option value="">All approvals</option>
        @foreach (['draft', 'review', 'approved', 'rejected'] as $value)
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
                <th class="px-4 py-2.5 font-medium">{{ Str::title(str_replace('_', ' ', preg_replace('/\.(name|code)$/', '', $column))) }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @forelse ($records as $record)
              <tr onclick="window.location='{{ route('tan90.brc.show', [$entity, $record->id]) }}'" style="border-top: 1px solid var(--border); cursor: pointer;" class="hover:bg-black/5">
                @foreach ($config['columns'] as $column)
                  <td class="px-4 py-2.5" style="color: var(--text-primary);">
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
              <tr><td colspan="{{ count($config['columns']) }}" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No {{ strtolower($config['title']) }} yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $records->links() }}</div>
    </div>
  </div>
</x-app-layout>
