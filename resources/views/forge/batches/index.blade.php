<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Batch Genealogy</div>
            <h1 class="text-2xl font-bold">Batch Genealogy</h1>
        </div>

        <div class="access-card p-5">
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Batch</th><th>Work Order</th><th>Finished Good</th><th>Qty</th><th>Status</th><th>Released</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td>{{ $batch->batch_number }}</td>
                                <td>{{ $batch->workOrder?->wo_number }}</td>
                                <td>{{ $batch->workOrder?->finishedGood?->name }}</td>
                                <td>{{ $batch->qty }} {{ $batch->uom }}</td>
                                <td><span class="access-chip">{{ str($batch->status)->headline() }}</span></td>
                                <td>{{ $batch->released_at?->format('d M Y, H:i') ?? '—' }}</td>
                                <td><a class="access-btn" href="{{ route('forge.batches.show', $batch) }}">Trace</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="access-muted text-center py-6">No finished batches released yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $batches->links() }}
        </div>
    </div>
</x-app-layout>
