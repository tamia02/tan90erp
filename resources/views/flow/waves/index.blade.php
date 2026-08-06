<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Wave Builder</div>
            <h1 class="text-2xl font-bold">Wave Builder & Picking</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.waves.store') }}">
            @csrf
            <h2 class="font-bold">Build Wave from Allocated Lines</h2>
            <div class="space-y-2">
                @forelse ($reservedAllocations as $allocation)
                    <label class="flex items-center gap-2 text-sm border rounded p-2" style="border-color:#dfe7e2">
                        <input type="checkbox" name="order_line_ids[]" value="{{ $allocation->orderLine->id }}">
                        {{ $allocation->orderLine->order?->order_number }} — {{ $allocation->orderLine->finishedGood?->name }} — {{ $allocation->qty }} {{ $allocation->orderLine->uom }}
                    </label>
                @empty
                    <p class="access-muted text-sm">No reserved allocations waiting for a wave.</p>
                @endforelse
            </div>
            <input class="access-input max-w-sm" name="warehouse" placeholder="Warehouse" value="Bhiwandi FG Warehouse">
            <button class="access-btn access-btn-primary">Build Wave</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Waves</h2>
            <div class="space-y-4">
                @forelse ($waves as $wave)
                    <div class="border rounded p-3" style="border-color:#dfe7e2">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <strong>{{ $wave->wave_number }}</strong>
                            <span class="access-chip">{{ str($wave->status)->headline() }}</span>
                            @if ($wave->status === 'draft')
                                <form method="post" action="{{ route('flow.waves.publish', $wave) }}">
                                    @csrf
                                    <button class="access-btn access-btn-primary">Publish</button>
                                </form>
                            @endif
                        </div>
                        <div class="mt-2 space-y-1">
                            @foreach ($wave->pickTasks as $task)
                                <div class="text-sm flex items-center justify-between gap-2 border-t pt-1" style="border-color:#eef1ee">
                                    <span>{{ $task->allocation->orderLine->order?->order_number }} — {{ $task->allocation->orderLine->finishedGood?->name }} — to pick {{ $task->qty_to_pick }}</span>
                                    @if ($task->status === 'pending' && $wave->status === 'published')
                                        <form method="post" action="{{ route('flow.waves.pick-tasks.confirm', $task) }}" class="flex gap-2">
                                            @csrf
                                            <input class="access-input" style="width:100px" name="qty_picked" type="number" step="0.001" min="0" value="{{ $task->qty_to_pick }}" required>
                                            <button class="access-btn">Confirm Pick</button>
                                        </form>
                                    @else
                                        <span class="access-chip">{{ $task->status }} ({{ $task->qty_picked }})</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="access-muted text-sm">No waves built yet.</p>
                @endforelse
            </div>
            {{ $waves->links() }}
        </div>
    </div>
</x-app-layout>
