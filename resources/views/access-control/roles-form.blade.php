<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Roles / {{ $role->exists ? 'Edit' : 'Create' }}</div>
            <h1 class="text-2xl font-bold">{{ $role->exists ? $role->name : 'Create Role' }}</h1>
        </div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        <form class="space-y-5" method="post" action="{{ $role->exists ? route('access.roles.update',$role) : route('access.roles.store') }}">
            @csrf @if($role->exists) @method('put') @endif
            <div class="access-card p-5 grid md:grid-cols-3 gap-4">
                <input class="access-input" name="name" value="{{ old('name',$role->name) }}" placeholder="Name" required>
                <input class="access-input" name="label" value="{{ old('label',$role->label) }}" placeholder="Label" required>
                <input class="access-input" name="code" value="{{ old('code',$role->code) }}" placeholder="Code" required @readonly($role->is_system)>
                <select class="access-input" name="level">@foreach([1=>'Super Admin',2=>'Head of Vertical',3=>'Manager',4=>'Executive'] as $k=>$v)<option value="{{ $k }}" @selected(old('level',$role->level)==$k)>{{ $v }}</option>@endforeach</select>
                <select class="access-input" name="parent_role_id"><option value="">Parent role</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected(old('parent_role_id',$role->parent_role_id)==$parent->id)>{{ $parent->name }}</option>@endforeach</select>
                <select class="access-input" name="vertical_id"><option value="">All verticals</option>@foreach($verticals as $vertical)<option value="{{ $vertical->id }}" @selected(old('vertical_id',$role->vertical_id)==$vertical->id)>{{ $vertical->name }}</option>@endforeach</select>
                <select class="access-input" name="status"><option value="active" @selected(old('status',$role->status)==='active')>Active</option><option value="inactive" @selected(old('status',$role->status)==='inactive')>Inactive</option></select>
                <textarea class="access-input md:col-span-2" name="description" placeholder="Role description">{{ old('description',$role->description) }}</textarea>
            </div>
            <div class="access-card p-5 space-y-4" x-data="{q:'',tab:'{{ $permissions->first()?->module }}'}">
                <div class="flex items-center justify-between gap-3"><h2 class="font-bold">Permissions</h2><input class="access-input max-w-sm" x-model="q" placeholder="Search permissions"></div>
                <div class="access-tabs">@foreach($permissions->pluck('module')->unique() as $module)<button type="button" class="access-tab" :class="tab==='{{ $module }}' && 'active'" @click="tab='{{ $module }}'">{{ $module }}</button>@endforeach</div>
                @foreach($permissions->groupBy('module') as $module => $modulePermissions)
                    <div x-show="tab==='{{ $module }}'" class="space-y-2">
                        @foreach($modulePermissions->groupBy('screen') as $screen => $screenPermissions)
                            <div class="border rounded-lg p-3" style="border-color:#dfe7e2">
                                <div class="font-semibold mb-2">{{ $screen }}</div>
                                <div class="space-y-2">
                                    @foreach($screenPermissions as $permission)
                                        @php($pivot = $selected->get($permission->id)?->pivot)
                                        <div class="permission-builder-row" x-show="'{{ strtolower($permission->label.' '.$permission->key.' '.$permission->category) }}'.includes(q.toLowerCase())">
                                            <div>
                                                <strong>{{ $permission->label }}</strong>
                                                <small class="block access-muted">{{ $permission->key }} / {{ $permission->category }}</small>
                                            </div>
                                            <label><span>Effect</span><select name="permissions[{{ $permission->id }}][effect]" class="access-input"><option value="inherit" @selected(($pivot?->effect ?? 'inherit')==='inherit')>Inherit</option><option value="allow" @selected(($pivot?->effect ?? ($pivot?->allowed ? 'allow' : 'inherit'))==='allow')>Allow</option><option value="deny" @selected(($pivot?->effect ?? null)==='deny')>Deny</option></select></label>
                                            <label><span>Scope</span><select name="permissions[{{ $permission->id }}][scope_type]" class="access-input">@foreach(\App\Services\Access\AccessControlService::SCOPES as $scope)<option value="{{ $scope }}" @selected(($pivot?->scope_type ?? 'self')===$scope)>{{ $scope }}</option>@endforeach</select></label>
                                            <label><span>Max scope</span><select name="permissions[{{ $permission->id }}][max_scope_type]" class="access-input">@foreach(\App\Services\Access\AccessControlService::SCOPES as $scope)<option value="{{ $scope }}" @selected(($pivot?->max_scope_type ?? $pivot?->scope_type ?? 'self')===$scope)>{{ $scope }}</option>@endforeach</select></label>
                                            <label><span>Field mode</span><select name="permissions[{{ $permission->id }}][field_mode]" class="access-input"><option value="full" @selected(($pivot?->field_mode ?? 'full')==='full')>Full</option><option value="masked" @selected(($pivot?->field_mode ?? null)==='masked')>Masked</option><option value="readonly" @selected(($pivot?->field_mode ?? null)==='readonly')>Readonly</option><option value="hidden" @selected(($pivot?->field_mode ?? null)==='hidden')>Hidden</option></select></label>
                                            <div class="dashboard-builder-checks">
                                                <label><input type="checkbox" name="permissions[{{ $permission->id }}][delegable]" @checked($pivot?->delegable)> Delegable</label>
                                                <label><input type="checkbox" name="permissions[{{ $permission->id }}][locked]" @checked($pivot?->locked)> Locked</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div class="access-card p-5">
                <div class="access-tabs mb-4"><span class="access-tab active">Data Scope</span><span class="access-tab">Users</span><span class="access-tab">Default Dashboard</span><span class="access-tab">Effective Access Preview</span><span class="access-tab">Activity</span></div>
                <p class="access-muted text-sm">Server validation enforces hierarchy, delegation ceilings and scope ceilings. Assigned users: {{ $role->users?->count() ?? 0 }}. Activity entries: {{ $activity->count() }}.</p>
            </div>
            <button class="access-btn access-btn-primary">Save Role</button>
        </form>
    </div>
</x-app-layout>
