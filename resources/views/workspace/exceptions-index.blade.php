<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-6xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm access-muted">Workspace / Alerts &amp; Exceptions</div>
                <h1 class="text-2xl font-bold">Alerts &amp; Exceptions</h1>
            </div>
            <a class="access-btn" href="{{ route('workspace.index') }}">Back to Command Center</a>
        </div>

        @if (session('status'))
            <div class="access-card p-3 text-sm">{{ session('status') }}</div>
        @endif

        <div class="access-tabs">
            @foreach (['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'all' => 'All'] as $value => $label)
                <a class="access-tab @if($status === $value) active @endif" href="{{ route('workspace.exceptions.index', ['status' => $value]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="access-card overflow-hidden">
            <table class="access-table">
                <thead>
                    <tr><th>Exception</th><th>Category</th><th>Module</th><th>Severity</th><th>Assigned To</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($exceptions as $exception)
                        <tr>
                            <td><strong>{{ $exception->title }}</strong>@if($exception->resolution_notes)<div class="access-muted text-xs">{{ Str::limit($exception->resolution_notes, 80) }}</div>@endif</td>
                            <td>{{ $exception->category }}</td>
                            <td>{{ $exception->module }}</td>
                            <td><span class="access-chip" @if($exception->severity === 'critical') style="background:#fdecec;color:#b3261e" @endif>{{ ucfirst($exception->severity) }}</span></td>
                            <td>{{ $exception->assignee?->name ?? '—' }}</td>
                            <td>{{ ucfirst($exception->status) }}</td>
                            <td class="space-x-2">
                                @if ($canAssign && $exception->status === 'open')
                                    <form class="inline" method="post" action="{{ route('workspace.exceptions.acknowledge', $exception) }}">@csrf<button class="access-btn">Acknowledge</button></form>
                                @endif
                                @if ($canAssign && $exception->status !== 'resolved')
                                    <form class="inline" method="post" action="{{ route('workspace.exceptions.resolve', $exception) }}">@csrf<button class="access-btn access-btn-primary">Resolve</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="access-muted text-center py-6">No exceptions raised.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $exceptions->links() }}
    </div>
</x-app-layout>
