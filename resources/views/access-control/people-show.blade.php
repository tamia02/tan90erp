<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / People / {{ $person->name }}</div>
            <h1 class="text-2xl font-bold">{{ $person->name }}</h1>
            <p class="access-muted">{{ $person->email }} / {{ ucfirst($person->access_mode) }} mode</p>
        </div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="access-card p-3 text-sm" style="color:#b42318;">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif

        @php
            $mainRole = $person->role;
            $extraRoleValues = $person->extraRoleRecords->map(fn ($record) => $record->role?->value)->filter()->all();
        @endphp
        <div class="access-card p-5 space-y-4">
            <div>
                <h2 class="font-bold">Job roles</h2>
                <p class="access-muted text-sm">What this person does day to day. The <b>main role</b> decides the home page they land on after login. Each <b>extra role</b> adds that role's screens to their sidebar, so one person can work as, for example, Store Manager and QC.</p>
            </div>
            @if ($canEditJobRoles)
                <form method="post" action="{{ route('access.people.job-roles', $person) }}" class="space-y-4" x-data="{ main: @js(old('main_role', $mainRole?->value ?? '')) }">
                    @csrf
                    <div class="max-w-sm">
                        <label class="text-xs font-bold access-muted uppercase">Main role</label>
                        <select class="access-input mt-1" name="main_role" x-model="main">
                            <option value="">None (Access Control only)</option>
                            @foreach ($combinableRoles as $role)
                                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold access-muted uppercase">Extra roles</label>
                        <div class="flex flex-wrap gap-2 mt-1">
                            @foreach ($combinableRoles as $role)
                                <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2" style="border-color:#dfe7e2;" x-show="main !== '{{ $role->value }}'">
                                    <input type="checkbox" name="extra_roles[]" value="{{ $role->value }}" :disabled="main === '' || main === '{{ $role->value }}'" @checked(in_array($role->value, old('extra_roles', $extraRoleValues), true))>
                                    <span>{{ $role->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs access-muted mt-1" x-show="main === ''">Pick a main role first to add extra roles.</p>
                    </div>
                    <button class="access-btn access-btn-primary">Save job roles</button>
                </form>
            @else
                <div class="flex flex-wrap gap-2">
                    @if ($mainRole)<span class="access-chip">{{ $mainRole->label() }} (main)</span>@endif
                    @foreach ($person->extraRoles() as $role)<span class="access-chip">{{ $role->label() }}</span>@endforeach
                    @if (! $mainRole)<span class="access-muted text-sm">None</span>@endif
                </div>
                <p class="text-xs access-muted">
                    @if (in_array($mainRole, [\App\Enums\Role::Admin, \App\Enums\Role::Vendor], true))
                        Super Admin and Vendor accounts are managed in Admin &gt; Users.
                    @else
                        Only a Super Admin can change job roles.
                    @endif
                </p>
            @endif
        </div>

        <div class="access-card p-5 space-y-3">
            <div>
                <h2 class="font-bold">Access Control roles</h2>
                <p class="access-muted text-sm">Roles from Access Control &gt; Roles. A person can hold several; their permissions add together.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @forelse ($person->accessRoles as $role)
                    <span class="access-chip items-center gap-1">
                        {{ $role->name }}
                        @if ($canManageThisUser && ! (auth()->user()->is($person) && $role->code === 'ACCESS-SUPER-ADMIN'))
                            <form method="post" action="{{ route('access.people.remove-role', [$person, $role]) }}" onsubmit="return confirm('Remove {{ addslashes($role->name) }} from {{ addslashes($person->name) }}?')">
                                @csrf @method('DELETE')
                                <button title="Remove this role" aria-label="Remove {{ $role->name }}" class="ml-1 font-black leading-none">&times;</button>
                            </form>
                        @endif
                    </span>
                @empty
                    <span class="access-muted text-sm">No Access Control role yet.</span>
                @endforelse
            </div>
            @if ($canManageThisUser)
                <form method="post" action="{{ route('access.people.assign-role', $person) }}" class="flex flex-wrap gap-2 max-w-xl">
                    @csrf
                    <select class="access-input" style="width:auto;flex:1" name="role_id">
                        @foreach ($roles->whereNotIn('id', $person->accessRoles->pluck('id')) as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <button class="access-btn">Add role</button>
                </form>
            @endif
        </div>

        <div class="access-card p-5">
            <div class="access-tabs mb-4"><span class="access-tab active">Profile & Position</span><span class="access-tab">Roles</span><span class="access-tab">Extra Permissions</span><span class="access-tab">Restrictions</span><span class="access-tab">Saved Views</span><span class="access-tab">Dashboard</span><span class="access-tab">Effective Access</span><span class="access-tab">Activity</span></div>
            <div class="access-grid">
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
