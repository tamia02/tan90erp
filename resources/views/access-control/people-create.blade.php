<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-4xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / People / Add User</div>
            <h1 class="text-2xl font-bold">Add User</h1>
            <p class="access-muted text-sm mt-1">Create the person, assign their role, and - if they're a Head or Manager - pick the existing people who now report to them. Their combined dashboard is built automatically from whatever those people can already see; nobody needs to design it by hand.</p>
        </div>

        @if ($errors->any())
            <div class="access-card p-4 text-sm" style="color:#b42318;">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('access.people.store') }}" class="access-card p-5 space-y-5">
            @csrf

            <div class="access-grid">
                <div>
                    <label class="text-xs font-bold access-muted uppercase">Name</label>
                    <input class="access-input mt-1" name="name" value="{{ old('name') }}" required>
                </div>
                <div>
                    <label class="text-xs font-bold access-muted uppercase">Email</label>
                    <input class="access-input mt-1" type="email" name="email" value="{{ old('email') }}" required>
                </div>
                <div>
                    <label class="text-xs font-bold access-muted uppercase">Password</label>
                    <input class="access-input mt-1" type="password" name="password" required minlength="8">
                </div>
                <div>
                    <label class="text-xs font-bold access-muted uppercase">Access Control role{{ $canSetJobRoles ? ' (optional if a main job role is picked below)' : '' }}</label>
                    <select class="access-input mt-1" name="role_id" @required(! $canSetJobRoles)>
                        <option value="">{{ $canSetJobRoles ? 'None' : 'Select a role' }}</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>
                                {{ $role->name }} (Level {{ $role->hierarchy_level ?? $role->level }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs access-muted mt-1">The role controls which screens and actions this user can access. Choose the closest approved role; an Admin can update its permissions later.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs font-bold access-muted uppercase">Reports To (this person's own manager, optional)</label>
                    <select class="access-input mt-1" name="reports_to_user_id">
                        <option value="">None (top level)</option>
                        @foreach ($people as $candidate)
                            <option value="{{ $candidate->id }}" @selected(old('reports_to_user_id') == $candidate->id)>{{ $candidate->name }} ({{ $candidate->email }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($canSetJobRoles)
                <div class="space-y-3" x-data="{ main: @js(old('main_role', '')) }">
                    <div>
                        <label class="text-xs font-bold access-muted uppercase">Job roles (Guard, Store, QC, Finance screens)</label>
                        <p class="text-xs access-muted">The <b>main role</b> decides their home page after login. Tick <b>extra roles</b> if the same person also does other jobs - e.g. main role Store Manager, extra roles Store Executive and QC.</p>
                    </div>
                    <div class="max-w-sm">
                        <label class="text-xs font-bold access-muted uppercase">Location (site)</label>
                        <select class="access-input mt-1" name="location_id">
                            <option value="">All sites</option>
                            @foreach ($siteLocations as $site)
                                <option value="{{ $site->id }}" @selected(old('location_id') == $site->id)>{{ $site->name }} ({{ $site->city }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs access-muted mt-1">They see only this site's gate entries, POs, GRN and shipments. "All sites" = everything, as before.</p>
                    </div>
                    <div class="max-w-sm">
                        <select class="access-input" name="main_role" x-model="main">
                            <option value="">No main role</option>
                            @foreach ($combinableRoles as $role)
                                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-wrap gap-2" x-show="main !== ''">
                        @foreach ($combinableRoles as $role)
                            <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2" style="border-color:#dfe7e2;" x-show="main !== '{{ $role->value }}'">
                                <input type="checkbox" name="extra_roles[]" value="{{ $role->value }}" :disabled="main === '' || main === '{{ $role->value }}'" @checked(collect(old('extra_roles'))->contains($role->value))>
                                <span>{{ $role->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <label class="text-xs font-bold access-muted uppercase">Team reporting to this person</label>
                <p class="text-xs access-muted mb-2">Check every existing person who should now report to this new user - e.g. a Guard, a Store Executive and a Store Manager reporting to a new Head. Their combined dashboard totals and widgets come from exactly this selection.</p>
                <div class="access-grid" style="max-height:320px;overflow-y:auto;">
                    @foreach ($people as $candidate)
                        <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2" style="border-color:#dfe7e2;">
                            <input type="checkbox" name="report_user_ids[]" value="{{ $candidate->id }}" @checked(collect(old('report_user_ids'))->contains($candidate->id))>
                            <span>{{ $candidate->name }} <span class="access-muted">({{ $candidate->email }})</span></span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="access-btn access-btn-primary">Create User</button>
                <a href="{{ route('access.people.index') }}" class="access-btn">Cancel</a>
            </div>
        </form>
    </div>
</x-app-layout>
