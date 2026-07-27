<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5"><div class="text-sm access-muted">Access Control / Activity</div><h1 class="text-2xl font-bold">Activity</h1></div>
        <form class="access-card p-4 flex gap-3"><input class="access-input max-w-sm" name="action" value="{{ request('action') }}" placeholder="Filter action"><button class="access-btn">Filter</button></form>
        <div class="access-card overflow-hidden">
            <table class="access-table"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>After</th></tr></thead><tbody>
                @foreach($logs as $log)
                    <tr><td>{{ $log->created_at }}</td><td>{{ $log->actor_id ?? 'system' }}</td><td>{{ $log->action }}</td><td>{{ class_basename($log->target_type) }} #{{ $log->target_id }}</td><td><code class="text-xs">{{ str($log->after_json ? json_encode($log->after_json) : '')->limit(140) }}</code></td></tr>
                @endforeach
            </tbody></table>
        </div>
        {{ $logs->links() }}
    </div>
</x-app-layout>
