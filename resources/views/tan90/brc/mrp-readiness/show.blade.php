<x-app-layout>
  <x-slot name="header">
    <h2 class="font-semibold text-xl leading-tight" style="color: var(--text-primary);">{{ $finishedGood->name }}</h2>
  </x-slot>

  <div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
      <p class="text-sm" style="color: var(--text-secondary);">MRP Readiness</p>
      <a href="{{ route('tan90.brc.mrp-readiness.index') }}" class="inline-flex items-center justify-center rounded-lg px-3.5 py-2 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">← Back</a>
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-sm" style="color: var(--text-primary);">{{ $result['ready'] ? 'Ready for MRP' : 'Not Ready' }}</h3>
        @include('tan90.brc.partials.status-badge', ['value' => $result['ready'] ? 'mrp_ready' : 'pending'])
      </div>
      @if ($result['ready'])
        <p class="text-sm py-4" style="color: var(--status-good);">All required masters are released.</p>
      @else
        <div class="flex flex-col gap-2">
          @foreach ($result['blockers'] as $blocker)
            <div class="text-sm rounded-lg px-3 py-2" style="background: var(--status-critical-bg); color: var(--status-critical);">{{ $blocker }}</div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</x-app-layout>
