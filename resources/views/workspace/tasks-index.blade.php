<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-6xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm access-muted">Workspace / My Work</div>
                <h1 class="text-2xl font-bold">My Work</h1>
            </div>
            <a class="access-btn" href="{{ route('workspace.index') }}">Back to Command Center</a>
        </div>

        @if (session('status'))
            <div class="access-card p-3 text-sm">{{ session('status') }}</div>
        @endif

        <div class="access-tabs">
            @foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'all' => 'All'] as $value => $label)
                <a class="access-tab @if($status === $value) active @endif" href="{{ route('workspace.tasks.index', ['status' => $value]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="access-card overflow-hidden">
            <table class="access-table">
                <thead>
                    <tr><th>Task</th><th>Module</th><th>Priority</th><th>Assigned To</th><th>Due</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        <tr>
                            <td><strong>{{ $task->title }}</strong>@if($task->description)<div class="access-muted text-xs">{{ Str::limit($task->description, 80) }}</div>@endif</td>
                            <td>{{ $task->module }}</td>
                            <td><span class="access-chip">{{ ucfirst($task->priority) }}</span></td>
                            <td>{{ $task->assignee?->name ?? '—' }}</td>
                            <td>{{ $task->due_at?->format('d M, H:i') ?? '—' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $task->status)) }}</td>
                            <td class="space-x-2">
                                @if ($canComplete && $task->status !== 'completed')
                                    @if (! $task->assigned_to || $task->assigned_to !== auth()->id())
                                        <form class="inline" method="post" action="{{ route('workspace.tasks.claim', $task) }}">@csrf<button class="access-btn">Claim</button></form>
                                    @endif
                                    <form class="inline" method="post" action="{{ route('workspace.tasks.complete', $task) }}">@csrf<button class="access-btn access-btn-primary">Complete</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="access-muted text-center py-6">No tasks here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $tasks->links() }}
    </div>
</x-app-layout>
