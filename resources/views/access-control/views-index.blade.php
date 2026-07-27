<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5"><div class="text-sm access-muted">Access Control / Views</div><h1 class="text-2xl font-bold">Saved View Builder</h1></div>
        <div class="grid lg:grid-cols-[260px_1fr_260px] gap-4">
            <aside class="access-card p-4"><h2 class="font-bold">Available Columns</h2><p class="access-muted text-sm mt-2">Registered columns, filters, grouping, masking and row actions are constrained by permission and field mode.</p></aside>
            <section class="access-card p-4">
                <div class="flex justify-between gap-3 mb-4"><input class="access-input max-w-sm" placeholder="Search saved views"><span class="access-chip">Draft / Publish</span></div>
                <table class="access-table"><thead><tr><th>Name</th><th>Module</th><th>Screen</th><th>Status</th><th>Version</th></tr></thead><tbody>
                    @foreach($views as $view)<tr><td><strong>{{ $view->name }}</strong><div class="access-muted text-xs">{{ $view->key }}</div></td><td>{{ $view->module }}</td><td>{{ $view->screen_key }}</td><td>{{ $view->status }}</td><td>{{ $view->version }}</td></tr>@endforeach
                </tbody></table>
                {{ $views->links() }}
            </section>
            <aside class="access-card p-4"><h2 class="font-bold">Properties</h2><p class="access-muted text-sm mt-2">Assign defaults to role, team or user. Locked parts cannot be personalised by lower levels.</p></aside>
        </div>
    </div>
</x-app-layout>
