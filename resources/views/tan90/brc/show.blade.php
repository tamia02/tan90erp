<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $record->{$config['primary']} ?? $config['singular'] }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">{{ $config['title'] }} / Detail</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $record->{$config['code']} ?? '' }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="{{ route('tan90.brc.index', $entity) }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>

        @can('update', $record)
          @if ($record->status !== 'archived')
            <a href="{{ route('tan90.brc.edit', [$entity, $record->id]) }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Edit</a>
          @endif
        @endcan

        @can('submit', $record)
          @if (in_array($record->approval_status, ['draft', 'rejected']))
            <form method="POST" action="{{ route('tan90.brc.submit', [$entity, $record->id]) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">Submit for Approval</button>
            </form>
          @endif
        @endcan
        @can('approve', $record)
          @if ($record->approval_status === 'review')
            <form method="POST" action="{{ route('tan90.brc.approve', [$entity, $record->id]) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Approve</button>
            </form>
            <form method="POST" action="{{ route('tan90.brc.reject', [$entity, $record->id]) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">Reject</button>
            </form>
          @endif
        @endcan

        @if ($record->status === 'archived')
          @can('restore', $record)
            <form method="POST" action="{{ route('tan90.brc.restore', [$entity, $record->id]) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Restore</button>
            </form>
          @endcan
        @else
          @can('delete', $record)
            <form method="POST" action="{{ route('tan90.brc.destroy', [$entity, $record->id]) }}" onsubmit="return confirm('Archive this record?')">
              @csrf @method('DELETE')
              <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">Archive</button>
            </form>
          @endcan
        @endif
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Record Summary</h3>
          @include('tan90.brc.partials.status-badge', ['value' => $record->approval_status ?? $record->status])
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          @foreach ($record->getAttributes() as $key => $value)
            @continue(in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by']))
            <div>
              <div class="text-xs" style="color: var(--text-muted);">{{ Str::title(str_replace('_', ' ', preg_replace('/_id$/', '', $key))) }}</div>
              <div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—') }}</div>
            </div>
          @endforeach
        </div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Control Information</h3>
        <div class="flex flex-col gap-3 text-sm">
          <div><div class="text-xs" style="color: var(--text-muted);">Created</div><div style="color: var(--text-primary);">{{ $record->created_at?->format('d M Y, H:i') }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Last Updated</div><div style="color: var(--text-primary);">{{ $record->updated_at?->format('d M Y, H:i') }}</div></div>
          @if (isset($record->version))
            <div><div class="text-xs" style="color: var(--text-muted);">Version</div><div style="color: var(--text-primary);">v{{ $record->version }}</div></div>
          @endif
        </div>
      </div>
    </div>

    <div class="rounded-lg border p-4 mt-4" style="background: var(--surface-3); border-color: var(--border);">
      <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Record Audit</h3>
      @if ($auditTrail->count())
        <div class="flex flex-col divide-y" style="border-color: var(--border);">
          @foreach ($auditTrail as $log)
            <div class="py-3">
              <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $log->action }} · {{ $log->user?->name ?? 'System' }}</div>
              <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $log->created_at?->format('d M Y, H:i') }} — {{ $log->description }}</div>
            </div>
          @endforeach
        </div>
      @else
        <p class="text-sm py-4" style="color: var(--text-muted);">No audit events yet.</p>
      @endif
    </div>
  </div>
</x-app-layout>
