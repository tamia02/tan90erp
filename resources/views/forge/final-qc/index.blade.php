<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Forge / Final Quality</div>
            <h1 class="text-2xl font-bold">Final Quality & FG Release</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Pending Final QC</h2>
            <div class="space-y-4">
                @forelse ($pending as $wo)
                    <form class="border rounded p-3 space-y-2" style="border-color:#dfe7e2" method="post" action="{{ route('forge.final-qc.store', $wo) }}">
                        @csrf
                        <div class="font-semibold">{{ $wo->wo_number }} — {{ $wo->finishedGood?->name }} (reconciled good qty {{ $wo->good_qty }})</div>
                        <div class="access-grid">
                            <input class="access-input" name="accepted_qty" type="number" step="0.001" min="0" placeholder="Accepted qty" required>
                            <input class="access-input" name="rejected_qty" type="number" step="0.001" min="0" placeholder="Rejected qty">
                            <input class="access-input" name="rework_qty" type="number" step="0.001" min="0" placeholder="Rework qty">
                            <select class="access-input" name="result" required>
                                <option value="released">Release</option>
                                <option value="rework">Rework</option>
                                <option value="rejected">Reject</option>
                            </select>
                        </div>
                        <textarea class="access-input" name="specification_results" placeholder="Specification results"></textarea>
                        <button class="access-btn access-btn-primary">Record Final QC</button>
                    </form>
                @empty
                    <p class="access-muted text-sm">Nothing waiting for final QC.</p>
                @endforelse
            </div>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Results</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Work Order</th><th>Accepted</th><th>Rejected</th><th>Rework</th><th>Result</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($results as $result)
                            <tr>
                                <td>{{ $result->workOrder?->wo_number }}</td>
                                <td>{{ $result->accepted_qty }}</td>
                                <td>{{ $result->rejected_qty }}</td>
                                <td>{{ $result->rework_qty }}</td>
                                <td><span class="access-chip">{{ str($result->result)->headline() }}</span></td>
                                <td>
                                    @if (! $result->released_at)
                                        <form method="post" action="{{ route('forge.final-qc.release', $result) }}">
                                            @csrf
                                            <button class="access-btn access-btn-primary">Release</button>
                                        </form>
                                    @else
                                        <span class="access-muted text-sm">Released {{ $result->released_at->format('d M, H:i') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No final QC results yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $results->links() }}
        </div>
    </div>
</x-app-layout>
