<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Roles / {{ $role->exists ? 'Edit' : 'Create' }}</div>
            <h1 class="text-2xl font-bold">{{ $role->exists ? $role->name : 'Create Role' }}</h1>
        </div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="access-card p-3 text-sm" style="color:#b42318;">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        @endif
        <div class="access-card p-4 text-sm access-muted">
            A role is a <b>job title plus the list of things that job may do</b> (below). After saving, give it to people in
            <a class="underline" href="{{ route('access.people.index') }}">Access Control &gt; People</a>. One person can hold several roles; what they may do is everything their roles allow, added together.
        </div>
        <form class="space-y-5" method="post" action="{{ $role->exists ? route('access.roles.update',$role) : route('access.roles.store') }}"
              x-data="{ name: @js(old('name', $role->name ?? '')), label: @js(old('label', $role->label ?? '')), code: @js(old('code', $role->code ?? '')), autoFill: {{ $role->exists ? 'false' : 'true' }} }"
              x-init="$watch('name', value => { if (autoFill) { label = value; code = 'ACCESS-' + value.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, ''); } })">
            @csrf @if($role->exists) @method('put') @endif
            <div class="access-card p-5 space-y-4">
                <div class="grid md:grid-cols-3 gap-4">
                    <label class="md:col-span-1"><span class="text-xs font-bold access-muted uppercase">Role name</span>
                        <input class="access-input mt-1" name="name" x-model="name" placeholder="e.g. Store Manager" required>
                    </label>
                    <label><span class="text-xs font-bold access-muted uppercase">Seniority level</span>
                        <select class="access-input mt-1" name="level">@foreach([1=>'Super Admin (top, manages everyone)',2=>'Head (manages Managers)',3=>'Manager (manages Executives)',4=>'Executive (staff)'] as $k=>$v)<option value="{{ $k }}" @selected(old('level',$role->level)==$k)>{{ $v }}</option>@endforeach</select>
                    </label>
                    <label><span class="text-xs font-bold access-muted uppercase">Status</span>
                        <select class="access-input mt-1" name="status"><option value="active" @selected(old('status',$role->status)==='active')>Active (can be given to people)</option><option value="inactive" @selected(old('status',$role->status)==='inactive')>Inactive (hidden when adding people)</option></select>
                    </label>
                </div>
                <label class="block"><span class="text-xs font-bold access-muted uppercase">Description (optional)</span>
                    <textarea class="access-input mt-1" name="description" placeholder="What this person does, in one line">{{ old('description',$role->description) }}</textarea>
                </label>
                <details class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <summary class="cursor-pointer font-semibold">More options <span class="access-muted font-normal">- department, org chart, display label and code (usually leave as is)</span></summary>
                    <div class="grid md:grid-cols-2 gap-4 mt-3">
                        <label><span class="text-xs font-bold access-muted uppercase">Department</span>
                            <select class="access-input mt-1" name="vertical_id"><option value="">All departments</option>@foreach($verticals as $vertical)<option value="{{ $vertical->id }}" @selected(old('vertical_id',$role->vertical_id)==$vertical->id)>{{ $vertical->name }}</option>@endforeach</select>
                            <small class="access-muted">Heads and Managers can only manage roles in their own department.</small>
                        </label>
                        <label><span class="text-xs font-bold access-muted uppercase">Sits under (org chart only)</span>
                            <select class="access-input mt-1" name="parent_role_id"><option value="">Nobody (top of chart)</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected(old('parent_role_id',$role->parent_role_id)==$parent->id)>{{ $parent->name }}</option>@endforeach</select>
                            <small class="access-muted">Only for drawing the org chart. It does <b>not</b> copy any access from that role.</small>
                        </label>
                        <label><span class="text-xs font-bold access-muted uppercase">Display label</span>
                            <input class="access-input mt-1" name="label" x-model="label" @input="autoFill = false" required>
                            <small class="access-muted">Short name shown on people's role badges. Filled from the role name.</small>
                        </label>
                        <label><span class="text-xs font-bold access-muted uppercase">Code</span>
                            <input class="access-input mt-1" name="code" x-model="code" @input="autoFill = false" required @readonly($role->is_system)>
                            <small class="access-muted">Unique internal ID. Filled from the role name.</small>
                        </label>
                    </div>
                </details>
            </div>
            <div class="access-card p-5 space-y-4" x-data="{q:'',tab:'{{ $permissions->first()?->module }}'}">
                <div class="rounded-lg border p-3 text-sm" style="border-color:#dfe7e2;background:#f7faf8">
                    <h2 class="font-bold">What can this role do?</h2>
                    <p class="access-muted mt-1">Pick a module tab, then set each action:</p>
                    <ul class="access-muted mt-1 list-disc pl-5">
                        <li><strong>Allow</strong> - people with this role can do it.</li>
                        <li><strong>Not given</strong> - this role does not give it (the person may still get it from another role they hold).</li>
                        <li><strong>Block</strong> - this role explicitly forbids it.</li>
                    </ul>
                    <p class="access-muted mt-1">"More options" under an action limits <em>which records</em> they see (e.g. only their own, or their team's) - leave it alone unless you need that.</p>
                </div>
                <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Permissions</h3><label class="sr-only" for="permission-search">Search permissions</label><input id="permission-search" class="access-input max-w-sm" x-model="q" placeholder="Search permissions"></div>
                <div class="access-tabs" role="tablist" aria-label="Permission modules">@foreach($permissions->pluck('module')->unique() as $module)<button type="button" role="tab" :aria-selected="tab==='{{ $module }}'" class="access-tab cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" :class="tab==='{{ $module }}' && 'active'" @click="tab='{{ $module }}'">{{ $module }}</button>@endforeach</div>
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
                                            <label><span>Access</span><select aria-label="Access for {{ $permission->label }}" name="permissions[{{ $permission->id }}][effect]" class="access-input"><option value="inherit" @selected(($pivot?->effect ?? 'inherit')==='inherit')>Not given</option><option value="allow" @selected(($pivot?->effect ?? ($pivot?->allowed ? 'allow' : 'inherit'))==='allow')>Allow</option><option value="deny" @selected(($pivot?->effect ?? null)==='deny')>Block</option></select></label>
                                            <details class="md:col-span-2 rounded-lg border p-3" style="border-color:#dfe7e2">
                                                <summary class="cursor-pointer font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">More options <span class="access-muted font-normal">- which records, field visibility, passing on access</span></summary>
                                                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                                                    <label><span>Records this role can see</span><select aria-label="Record scope for {{ $permission->label }}" name="permissions[{{ $permission->id }}][scope_type]" class="access-input">@foreach(\App\Services\Access\AccessControlService::SCOPES as $scope)<option value="{{ $scope }}" @selected(($pivot?->scope_type ?? 'self')===$scope)>{{ ['self'=>'Own records','created_by_me'=>'Created by me','assigned'=>'Assigned to me','direct_reports'=>'Direct reports','team'=>'My team','unit'=>'My unit','vertical'=>'My vertical','plant'=>'My plant','location'=>'My location','warehouse'=>'My warehouse','vendor'=>'Assigned vendor','all'=>'All records'][$scope] ?? ucfirst(str_replace('_',' ',$scope)) }}</option>@endforeach</select></label>
                                                    <label><span>Maximum permitted scope</span><select aria-label="Maximum scope for {{ $permission->label }}" name="permissions[{{ $permission->id }}][max_scope_type]" class="access-input">@foreach(\App\Services\Access\AccessControlService::SCOPES as $scope)<option value="{{ $scope }}" @selected(($pivot?->max_scope_type ?? $pivot?->scope_type ?? 'self')===$scope)>{{ ['self'=>'Own records','created_by_me'=>'Created by me','assigned'=>'Assigned to me','direct_reports'=>'Direct reports','team'=>'My team','unit'=>'My unit','vertical'=>'My vertical','plant'=>'My plant','location'=>'My location','warehouse'=>'My warehouse','vendor'=>'Assigned vendor','all'=>'All records'][$scope] ?? ucfirst(str_replace('_',' ',$scope)) }}</option>@endforeach</select></label>
                                                    <label><span>Field visibility</span><select aria-label="Field visibility for {{ $permission->label }}" name="permissions[{{ $permission->id }}][field_mode]" class="access-input"><option value="full" @selected(($pivot?->field_mode ?? 'full')==='full')>Full details</option><option value="masked" @selected(($pivot?->field_mode ?? null)==='masked')>Mask sensitive details</option><option value="readonly" @selected(($pivot?->field_mode ?? null)==='readonly')>Read only</option><option value="hidden" @selected(($pivot?->field_mode ?? null)==='hidden')>Hide fields</option></select></label>
                                                    <div class="dashboard-builder-checks self-end"><label title="People with this role may give this same access to people below them"><input type="checkbox" name="permissions[{{ $permission->id }}][delegable]" @checked($pivot?->delegable)> Can pass on to their team</label><label title="Marks this rule as fixed so it is not changed casually"><input type="checkbox" name="permissions[{{ $permission->id }}][locked]" @checked($pivot?->locked)> Lock this rule</label></div>
                                                </div>
                                            </details>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div class="access-card p-5">
                <p class="access-muted text-sm">People with this role: {{ $role->users?->count() ?? 0 }}. Change history entries: {{ $activity->count() }}. You can only give access you are allowed to give yourself - anything beyond that is dropped when you save.</p>
            </div>
            <button class="access-btn access-btn-primary">Save Role</button>
        </form>
    </div>
</x-app-layout>
