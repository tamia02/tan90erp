<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / In-Process Quality</div>
            <h1 class="text-2xl font-bold">In-Process Quality</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('forge.quality-holds.store') }}">
            @csrf
            <h2 class="font-bold">Record IPQC Checkpoint</h2>
            <div class="access-grid">
                <select class="access-input" name="work_order_id" required>
                    <option value="">Work order</option>
                    @foreach ($workOrders as $wo)
                        <option value="{{ $wo->id }}">{{ $wo->wo_number }}</option>
                    @endforeach
                </select>
                <input class="access-input" name="checkpoint" placeholder="Checkpoint name" required>
                <select class="access-input" name="result" required>
                    <option value="pass">Pass</option>
                    <option value="fail">Fail — create process hold</option>
                </select>
            </div>
            <textarea class="access-input" name="evidence" placeholder="Evidence / notes"></textarea>
            <button class="access-btn access-btn-primary">Record</button>
        </form>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Checkpoints & Holds</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Work Order</th><th>Checkpoint</th><th>Result</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($holds as $hold)
                            <tr>
                                <td>{{ $hold->workOrder?->wo_number }}</td>
                                <td>{{ $hold->checkpoint }}</td>
                                <td><span class="access-chip">{{ $hold->result ?? '—' }}</span></td>
                                <td>{{ str($hold->status)->headline() }}</td>
                                <td>
                                    @if ($hold->status === 'open')
                                        <form method="post" action="{{ route('forge.quality-holds.release', $hold) }}">
                                            @csrf
                                            <button class="access-btn access-btn-primary">Release Hold</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No IPQC checkpoints recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $holds->links() }}
        </div>
    </div>
</x-app-layout>
