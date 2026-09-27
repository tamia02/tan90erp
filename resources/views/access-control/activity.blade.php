<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Activity</div>
            <h1 class="text-2xl font-bold">Activity</h1>
            <p class="access-muted text-sm mt-1">Every role, permission and hierarchy change made in Access Control — click a row to see exactly what was filled in.</p>
        </div>
        <form class="access-card p-4 flex gap-3 items-center">
            <input class="access-input max-w-sm" name="action" value="{{ request('action') }}" placeholder="Filter action">
            <button class="access-btn">Filter</button>
            <a class="access-btn" href="{{ route('access.activity.export', request()->only('action')) }}">Export CSV</a>
        </form>
        <div class="access-card overflow-hidden">
            <table class="access-table"><thead><tr><th>When</th><th>Who</th><th>What happened</th><th>Record</th></tr></thead><tbody>
                @foreach($logs as $log)
                    <tr class="cursor-pointer hover:bg-black/[.02]" onclick="window.location='{{ route('access.activity.show', $log) }}'">
                        <td>{{ $log->created_at?->format('d M Y, H:i') }}</td>
                        <td>{{ $log->actor_id ? ($actors[$log->actor_id] ?? 'User #'.$log->actor_id) : 'System' }}</td>
                        <td>{{ str($log->action)->replace(['.', '_'], ' ')->headline()->toString() }}</td>
                        <td>{{ $log->target_type ? str(class_basename($log->target_type))->headline()->toString().' #'.$log->target_id : '—' }}</td>
                    </tr>
                @endforeach
            </tbody></table>
        </div>
        {{ $logs->links() }}
    </div>
</x-app-layout>
