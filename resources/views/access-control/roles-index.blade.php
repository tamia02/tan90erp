<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between gap-4">
            <div>
                <div class="text-sm access-muted">Access Control / Roles</div>
                <h1 class="text-2xl font-bold">Manage Roles</h1>
            </div>
            <a class="access-btn access-btn-primary" href="{{ route('access.roles.create') }}">Add Role</a>
        </div>
        <form class="access-card p-4 grid md:grid-cols-5 gap-3">
            <input class="access-input md:col-span-2" name="search" value="{{ request('search') }}" placeholder="Search roles">
            <select class="access-input" name="level"><option value="">Level</option>@foreach([1=>'Super Admin',2=>'Head',3=>'Manager',4=>'Executive'] as $k=>$v)<option value="{{ $k }}" @selected(request('level')==$k)>{{ $v }}</option>@endforeach</select>
            <select class="access-input" name="vertical_id"><option value="">Vertical</option>@foreach($verticals as $vertical)<option value="{{ $vertical->id }}" @selected(request('vertical_id')==$vertical->id)>{{ $vertical->name }}</option>@endforeach</select>
            <button class="access-btn">Filter</button>
        </form>
        <div class="access-card overflow-hidden">
            <table class="access-table">
                <thead><tr><th>Name</th><th>Label</th><th>Hierarchy Level</th><th>Parent / Vertical</th><th>Permissions</th><th>Users</th><th>Actions</th></tr></thead>
                <tbody>
                    @foreach($roles as $role)
                        <tr>
                            <td><strong>{{ $role->name }}</strong><div class="access-muted text-xs">{{ $role->code }}</div></td>
                            <td>{{ $role->label }}</td>
                            <td><span class="access-chip">Level {{ $role->level }}</span></td>
                            <td>{{ $role->parent?->name ?? 'None' }}<div class="access-muted text-xs">{{ $role->vertical?->name ?? 'All verticals' }}</div></td>
                            <td>{{ $role->permissions_count }}</td>
                            <td>{{ $role->users_count }}</td>
                            <td class="space-x-2">
                                <a class="access-btn" href="{{ route('access.roles.edit',$role) }}">View</a>
                                @if($canManage($role))
                                    <form class="inline" method="post" action="{{ route('access.roles.clone',$role) }}">@csrf<button class="access-btn">Clone</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $roles->links() }}
    </div>
</x-app-layout>
