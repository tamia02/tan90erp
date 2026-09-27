<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $eco->code }}</h2>
  </x-slot>

  <div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">{{ ucfirst($eco->object_type) }} change order</p>
      <div class="flex gap-2">
        <a href="{{ route('tan90.brc.eco.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
        @can('approve', $eco)
          @if ($eco->status === 'draft')
            <form method="POST" action="{{ route('tan90.brc.eco.approve', $eco->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--status-good);">Approve</button>
            </form>
          @elseif ($eco->status === 'approved')
            <form method="POST" action="{{ route('tan90.brc.eco.implement', $eco->id) }}">
              @csrf <button type="submit" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Mark Implemented</button>
            </form>
          @endif
        @endcan
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-semibold text-sm" style="color: var(--text-primary);">Change Details</h3>
          @include('tan90.brc.partials.status-badge', ['value' => $eco->status])
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <div><div class="text-xs" style="color: var(--text-muted);">Object Type</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ ucfirst($eco->object_type) }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Object ID</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">#{{ $eco->object_id }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Reason</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ $eco->reason }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Requested By</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ $eco->requestedBy->name ?? '—' }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Requested At</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ $eco->requested_at?->format('d M Y, H:i') }}</div></div>
          <div><div class="text-xs" style="color: var(--text-muted);">Approved By</div><div class="font-medium mt-0.5" style="color: var(--text-primary);">{{ $eco->approvedBy->name ?? '—' }}</div></div>
        </div>
        @if ($eco->description)
          <p class="text-sm mt-3" style="color: var(--text-secondary);">{{ $eco->description }}</p>
        @endif
      </div>
      <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h3 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Change Impacts</h3>
        @forelse ($eco->changeImpacts as $impact)
          <div class="py-2" style="border-top: 1px solid var(--border);">
            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ ucfirst($impact->impacted_object_type) }} #{{ $impact->impacted_object_id }}</div>
            <div class="text-xs" style="color: var(--text-muted);">{{ $impact->impact_description }}</div>
          </div>
        @empty
          <p class="text-sm py-4" style="color: var(--text-muted);">No downstream impacts recorded.</p>
        @endforelse
      </div>
    </div>
  </div>
</x-app-layout>
