<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / People / {{ $person->name }}</div>
            <h1 class="text-2xl font-bold">{{ $person->name }}</h1>
            <p class="access-muted">{{ $person->email }} / {{ ucfirst($person->access_mode) }} mode</p>
        </div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        <div class="access-card p-5">
            <div class="access-tabs mb-4"><span class="access-tab active">Profile & Position</span><span class="access-tab">Roles</span><span class="access-tab">Extra Permissions</span><span class="access-tab">Restrictions</span><span class="access-tab">Saved Views</span><span class="access-tab">Dashboard</span><span class="access-tab">Effective Access</span><span class="access-tab">Activity</span></div>
            <div class="access-grid">
                <div><h2 class="font-bold">Roles</h2><div class="mt-2 flex flex-wrap gap-2">@foreach($person->accessRoles as $role)<span class="access-chip">{{ $role->name }}</span>@endforeach</div></div>
                <div><h2 class="font-bold">Position</h2><p class="access-muted text-sm">Level {{ $person->accessPositions->first()?->hierarchy_level ?? 'not set' }}</p></div>
            </div>
        </div>
        <form method="post" action="{{ route('access.people.extra-access', $person) }}" class="access-card p-5 space-y-4">
            @csrf
            <h2 class="font-bold">Add Extra Access</h2>
            <div class="grid md:grid-cols-3 gap-3">
                <select class="access-input md:col-span-2" name="permission_id">@foreach($permissions as $permission)<option value="{{ $permission->id }}">{{ $permission->module }} / {{ $permission->label }} / {{ $permission->key }}</option>@endforeach</select>
                <select class="access-input" name="effect"><option value="allow">Allow</option><option value="deny">Deny</option></select>
                <select class="access-input" name="scope_type">@foreach(\App\Services\Access\AccessControlService::SCOPES as $scope)<option value="{{ $scope }}">{{ $scope }}</option>@endforeach</select>
                <select class="access-input" name="field_mode"><option value="">No field mode</option><option value="hidden">Hidden</option><option value="visible">Visible</option><option value="readonly">Read-only</option><option value="editable">Editable</option><option value="masked">Masked</option></select>
                <input class="access-input" type="datetime-local" name="expires_at">
                <input class="access-input md:col-span-3" name="reason" placeholder="Required reason">
            </div>
            <button class="access-btn access-btn-primary">Preview and Save</button>
        </form>
        <div class="access-card overflow-hidden">
            <table class="access-table"><thead><tr><th>Permission</th><th>Effect</th><th>Scope</th><th>Field Mode</th><th>Expiry</th><th>Reason</th></tr></thead><tbody>
                @foreach($overrides as $override)<tr><td>{{ $override->permission?->key }}</td><td>{{ $override->effect }}</td><td>{{ $override->scope_type }}</td><td>{{ $override->field_mode }}</td><td>{{ $override->expires_at }}</td><td>{{ $override->reason }}</td></tr>@endforeach
            </tbody></table>
        </div>
    </div>
</x-app-layout>
