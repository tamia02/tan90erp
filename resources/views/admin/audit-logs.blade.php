<x-app-layout>
    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-5">
        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--brand);">Super Admin</div>
                    <h1 class="text-2xl font-bold mt-1" style="color:var(--text-primary);">Audit Logs</h1>
                    <p class="text-sm mt-1" style="color:var(--text-secondary);">Business activity, master data changes, and access-control events.</p>
                </div>
                <a href="{{ route('admin.audit-logs.export', request()->query()) }}" class="rounded-lg px-3.5 py-2 text-sm font-semibold text-white" style="background:var(--brand);">Export filtered CSV</a>
            </div>
        </section>

        <form method="get" class="rounded-xl border p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3" style="background:var(--surface-3);border-color:var(--border);">
            <input name="q" value="{{ request('q') }}" placeholder="Search action, detail, actor" class="rounded-lg border px-3 py-2 text-sm lg:col-span-2" style="background:var(--surface-1);border-color:var(--border);color:var(--text-primary);">
            <select name="source" class="rounded-lg border px-3 py-2 text-sm" style="background:var(--surface-1);border-color:var(--border);color:var(--text-primary);">
                <option value="">All sources</option>
                @foreach($sources as $source)<option value="{{ $source }}" @selected(request('source') === $source)>{{ $source }}</option>@endforeach
            </select>
            <select name="actor_id" class="rounded-lg border px-3 py-2 text-sm" style="background:var(--surface-1);border-color:var(--border);color:var(--text-primary);">
                <option value="">All users</option>
                @foreach($actors as $actor)<option value="{{ $actor->id }}" @selected((string) request('actor_id') === (string) $actor->id)>{{ $actor->name }}</option>@endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" aria-label="From date" class="rounded-lg border px-3 py-2 text-sm" style="background:var(--surface-1);border-color:var(--border);color:var(--text-primary);">
            <input type="date" name="to" value="{{ request('to') }}" aria-label="To date" class="rounded-lg border px-3 py-2 text-sm" style="background:var(--surface-1);border-color:var(--border);color:var(--text-primary);">
            <div class="flex gap-2 lg:col-span-6"><button class="rounded-lg px-4 py-2 text-sm font-semibold text-white" style="background:var(--brand);">Apply filters</button><a href="{{ route('admin.audit-logs') }}" class="rounded-lg border px-4 py-2 text-sm" style="border-color:var(--border);color:var(--text-primary);">Clear</a></div>
        </form>

        <div class="rounded-xl border overflow-hidden" style="background:var(--surface-3);border-color:var(--border);">
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left text-xs" style="color:var(--text-muted);border-bottom:1px solid var(--border);"><th class="px-4 py-3">When</th><th class="px-4 py-3">Source</th><th class="px-4 py-3">Actor</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Detail</th><th class="px-4 py-3">Target</th></tr></thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d M Y, H:i:s') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $log->source }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $log->actor_name ?: ($log->actor_id ? 'User #'.$log->actor_id : 'System / not recorded') }}</td>
                            <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $log->action }}</td>
                            <td class="px-4 py-3 min-w-64" style="color:var(--text-secondary);">{{ $log->detail ?: '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $log->target_type ? class_basename($log->target_type).' #'.$log->target_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center" style="color:var(--text-muted);">No audit entries match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <div class="p-4">{{ $logs->links() }}</div>
        </div>
    </div>
</x-app-layout>
