<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-6xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm access-muted">Workspace / Approval Center</div>
                <h1 class="text-2xl font-bold">Approval Center</h1>
            </div>
            <a class="access-btn" href="{{ route('workspace.index') }}">Back to Command Center</a>
        </div>

        @if (session('status'))
            <div class="access-card p-3 text-sm">{{ session('status') }}</div>
        @endif

        <div class="access-tabs">
            @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Returned', 'all' => 'All'] as $value => $label)
                <a class="access-tab @if($status === $value) active @endif" href="{{ route('workspace.approvals.index', ['status' => $value]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="access-card overflow-hidden">
            <table class="access-table">
                <thead>
                    <tr><th>Request</th><th>Module</th><th>Amount</th><th>Risk</th><th>Requested By</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($approvals as $approval)
                        <tr>
                            <td><strong>{{ $approval->subject }}</strong>@if($approval->decision_notes)<div class="access-muted text-xs">{{ Str::limit($approval->decision_notes, 80) }}</div>@endif</td>
                            <td>{{ $approval->module }}</td>
                            <td>{{ $approval->amount ? '₹'.number_format($approval->amount) : '—' }}</td>
                            <td><span class="access-chip">{{ ucfirst($approval->risk_level) }}</span></td>
                            <td>{{ $approval->requester?->name }}</td>
                            <td>{{ ucfirst($approval->status) }}</td>
                            <td class="space-x-2">
                                @if ($canDecide && $approval->status === 'pending')
                                    <form class="inline" method="post" action="{{ route('workspace.approvals.decide', $approval) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="approved">
                                        <button class="access-btn access-btn-primary">Approve</button>
                                    </form>
                                    <form class="inline" method="post" action="{{ route('workspace.approvals.decide', $approval) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="rejected">
                                        <button class="access-btn">Reject</button>
                                    </form>
                                    <form class="inline" method="post" action="{{ route('workspace.approvals.decide', $approval) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="returned">
                                        <button class="access-btn">Return</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="access-muted text-center py-6">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $approvals->links() }}
    </div>
</x-app-layout>
