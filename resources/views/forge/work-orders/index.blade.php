<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Work Orders</div>
            <h1 class="text-2xl font-bold">Work Orders</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        @include('forge.work-orders.partials.create-form')

        <div class="access-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="font-bold">Work Orders</h2>
                    @if($phaseLabel)<p class="access-muted text-sm">{{ $phaseLabel }}</p>@endif
                </div>
                @if($canExportWorkOrders)
                    <a class="access-btn" href="{{ route('forge.workorders.export', $filters) }}">Export ({{ $workOrders->count() }} shown)</a>
                @endif
            </div>
            <form method="get" class="flex flex-wrap gap-2 mb-4">
                @if(request('phase'))<input type="hidden" name="phase" value="{{ request('phase') }}">@endif
                <input class="access-input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search work order or finished good">
                <select class="access-input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>@endforeach</select>
                <button class="access-btn">Filter</button>
            </form>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>WO</th><th>Finished Good</th><th>Target Qty</th><th>Good/Rework/Rejected</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($workOrders as $wo)
                            <tr>
                                <td>{{ $wo->wo_number }}</td>
                                <td>{{ $wo->finishedGood?->name }}</td>
                                <td>{{ $wo->target_qty }} {{ $wo->uom }}</td>
                                <td>{{ $wo->good_qty }} / {{ $wo->rework_qty }} / {{ $wo->rejected_qty }}</td>
                                <td><span class="access-chip">{{ str($wo->status)->replace('_', ' ')->headline() }}</span></td>
                                @php($activeStep = match($wo->status) { 'draft', 'released', 'material_reserved' => 'materials', 'material_issued', 'in_progress', 'rework' => 'mixing', 'reconciliation' => 'filling-sealing', 'final_qc_pending' => 'quality', 'bundle_pending' => 'consumption-fg', default => 'finished-goods' })
                                <td><a class="access-btn" href="{{ route('forge.modules.workorder', ['step' => $activeStep, 'workOrder' => $wo]) }}">Open current step</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No work orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $workOrders->links() }}
        </div>
    </div>

    @include('forge.work-orders.partials.create-script')
</x-app-layout>
