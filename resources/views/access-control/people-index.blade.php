<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between gap-4">
            <div><div class="text-sm access-muted">Access Control / People</div><h1 class="text-2xl font-bold">Manage Users</h1></div>
            <a href="{{ route('access.people.create') }}" class="access-btn access-btn-primary">Add User</a>
        </div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        <form class="access-card p-4 grid md:grid-cols-5 gap-3">
            <input class="access-input md:col-span-2" name="search" value="{{ request('search') }}" placeholder="Search people">
            <select class="access-input" name="role_id"><option value="">Role</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected(request('role_id')==$role->id)>{{ $role->name }}</option>@endforeach</select>
            <select class="access-input" name="vertical"><option value="">Vertical</option>@foreach($verticals as $vertical)<option>{{ $vertical->name }}</option>@endforeach</select>
            <button class="access-btn">Filter</button>
        </form>
        <div class="access-grid">
            @foreach($users as $person)
                <article class="access-card p-4 space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full grid place-items-center text-white font-bold" style="background:#147d4f">{{ strtoupper(substr($person->name,0,1)) }}</div>
                        <div><h2 class="font-bold">{{ $person->name }}</h2><div class="access-muted text-sm">{{ $person->email }} @if($person->phone) / {{ $person->phone }} @endif</div></div>
                    </div>
                    <div class="flex flex-wrap gap-2">@forelse($person->accessRoles as $role)<span class="access-chip">{{ $role->label }}</span>@empty<span class="access-muted text-sm">No new access assignment</span>@endforelse</div>
                    <div class="text-sm access-muted">Status: {{ $person->is_active ? 'Active' : 'Inactive' }}. Legacy role: {{ $person->role?->label() ?? 'None' }}</div>
                    <a class="access-btn" href="{{ route('access.people.show', $person) }}">View access</a>
                    @if($canManageUser($person))
                        <form method="post" action="{{ route('access.people.assign-role',$person) }}" class="flex gap-2">
                            @csrf
                            <select class="access-input" name="role_id">@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select>
                            <button class="access-btn">Assign</button>
                        </form>
                    @else
                        <div class="text-xs access-muted">Hierarchy rule blocks editing this user.</div>
                    @endif
                </article>
            @endforeach
        </div>
        {{ $users->links() }}
    </div>
</x-app-layout>
