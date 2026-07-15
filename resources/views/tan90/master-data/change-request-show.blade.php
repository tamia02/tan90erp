<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $changeRequest->request_no }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">{{ $entity['title'] }} · {{ $changeRequest->record_code }}</p>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $changeRequest->reason }}</p>
      </div>
      <div class="flex gap-2">
        <a href="{{ route('tan90.master-data.change-requests.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
        @if (in_array($changeRequest->approval_status, ['pending', 'review']))
          @can('approve', $entity['model'])
            <form method="POST" action="{{ route('tan90.master-data.change-requests.approve', $changeRequest->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Approve & Apply</button>
            </form>
            <form method="POST" action="{{ route('tan90.master-data.change-requests.reject', $changeRequest->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-critical);">Reject</button>
            </form>
          @endcan
        @endif
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Proposed Changes</h3>
          @include('tan90.master-data.partials.status-badge', ['value' => $changeRequest->approval_status])
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          @foreach ($changeRequest->proposed_changes as $field => $newValue)
            <div>
              <div class="text-xs" style="color: var(--text-muted);">{{ Str::title(str_replace('_', ' ', $field)) }}</div>
              <div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ $changeRequest->previous_values[$field] ?? '—' }} → {{ $newValue }}</div>
            </div>
          @endforeach
        </div>
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Request Info</h3>
        <div class="flex flex-col gap-3 text-sm">
          <div><div class="text-xs" style="color: var(--text-muted);">Requested</div><div style="color: var(--text-primary);">{{ $changeRequest->created_at?->format('d M Y, H:i') }} by {{ $changeRequest->requester?->name }}</div></div>
          @if ($changeRequest->reviewed_at)
            <div><div class="text-xs" style="color: var(--text-muted);">Reviewed</div><div style="color: var(--text-primary);">{{ $changeRequest->reviewed_at?->format('d M Y, H:i') }} by {{ $changeRequest->reviewer?->name }}</div><div class="text-xs" style="color: var(--text-muted);">{{ $changeRequest->review_notes }}</div></div>
          @endif
        </div>
      </div>
    </div>

    <div class="rounded-lg border p-4 mt-4" style="background: var(--surface-3); border-color: var(--border);">
      <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Applied Versions</h3>
      @forelse ($changeRequest->versions as $version)
        <div class="text-sm py-2" style="border-top: 1px solid var(--border); color: var(--text-primary);">Version {{ $version->version_number }} · {{ $version->effective_from?->format('d M Y, H:i') }}</div>
      @empty
        <p class="text-sm py-4" style="color: var(--text-muted);">Not yet applied. A version snapshot is written once this request is approved.</p>
      @endforelse
    </div>
  </div>
</x-app-layout>
