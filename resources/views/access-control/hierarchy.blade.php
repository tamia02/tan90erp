<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Hierarchy</div>
            <h1 class="text-2xl font-bold">Hierarchy Structure</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="grid xl:grid-cols-4 md:grid-cols-2 gap-4">
            <form class="access-card p-4 space-y-3" method="post" action="{{ route('access.hierarchy.verticals.store') }}">
                @csrf
                <h2 class="font-bold">Add Vertical</h2>
                <input class="access-input" name="code" placeholder="STORE">
                <input class="access-input" name="name" placeholder="Store Operations">
                <textarea class="access-input" name="description" placeholder="Description"></textarea>
                <button class="access-btn access-btn-primary">Create Vertical</button>
            </form>

            <form class="access-card p-4 space-y-3" method="post" action="{{ route('access.hierarchy.units.store') }}">
                @csrf
                <h2 class="font-bold">Add Unit</h2>
                <select class="access-input" name="vertical_id">@foreach($verticals as $vertical)<option value="{{ $vertical->id }}">{{ $vertical->name }}</option>@endforeach</select>
                <input class="access-input" name="code" placeholder="BHI-WH">
                <input class="access-input" name="name" placeholder="Bhiwandi Warehouse">
                <button class="access-btn access-btn-primary">Create Unit</button>
            </form>

            <form class="access-card p-4 space-y-3" method="post" action="{{ route('access.hierarchy.teams.store') }}">
                @csrf
                <h2 class="font-bold">Add Team</h2>
                <select class="access-input" name="vertical_id">@foreach($verticals as $vertical)<option value="{{ $vertical->id }}">{{ $vertical->name }}</option>@endforeach</select>
                <select class="access-input" name="unit_id"><option value="">No unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select>
                <select class="access-input" name="manager_user_id"><option value="">No manager</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
                <input class="access-input" name="code" placeholder="GRN-CTRL">
                <input class="access-input" name="name" placeholder="GRN Control Team">
                <button class="access-btn access-btn-primary">Create Team</button>
            </form>

            <form class="access-card p-4 space-y-3" method="post" action="{{ route('access.hierarchy.positions.save') }}">
                @csrf
                <h2 class="font-bold">Assign Position</h2>
                <select class="access-input" name="user_id">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
                <select class="access-input" name="hierarchy_level">
                    <option value="1">Level 1 / Super Admin</option>
                    <option value="2">Level 2 / Head</option>
                    <option value="3">Level 3 / Manager</option>
                    <option value="4">Level 4 / Executive</option>
                </select>
                <select class="access-input" name="vertical_id"><option value="">All verticals</option>@foreach($verticals as $vertical)<option value="{{ $vertical->id }}">{{ $vertical->name }}</option>@endforeach</select>
                <select class="access-input" name="unit_id"><option value="">No unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select>
                <select class="access-input" name="team_id"><option value="">No team</option>@foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->name }}</option>@endforeach</select>
                <select class="access-input" name="reports_to_user_id"><option value="">Reports to nobody</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
                <button class="access-btn access-btn-primary">Save Position</button>
            </form>
        </div>

        <div class="access-card p-5">
            <h2 class="font-bold mb-4">Level 1-4 Reporting Map</h2>
            <div class="hierarchy-levels">
                @foreach([1 => 'Super Admin', 2 => 'Head of Vertical', 3 => 'Manager', 4 => 'Executive / Employee'] as $level => $label)
                    <section>
                        <h3>Level {{ $level }}</h3>
                        <p>{{ $label }}</p>
                        <div class="space-y-2 mt-3">
                            @foreach($positions->where('hierarchy_level', $level) as $position)
                                <div class="hierarchy-person">
                                    <strong>{{ $position->user?->name }}</strong>
                                    <span>{{ $position->vertical?->name ?? 'All verticals' }} / {{ $position->unit?->name ?? 'All units' }} / {{ $position->team?->name ?? 'All teams' }}</span>
                                    <small>Reports to: {{ $position->manager?->name ?? 'None' }}</small>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Roles by Vertical</h2>
                <div class="space-y-3">
                    @foreach($verticals as $vertical)
                        <details class="border rounded p-3" style="border-color:#dfe7e2" @open($loop->first)>
                            <summary class="font-semibold">{{ $vertical->name }}</summary>
                            <div class="mt-3 space-y-2">
                                @foreach($roles->where('vertical_id', $vertical->id) as $role)
                                    <div class="flex justify-between gap-2 text-sm">
                                        <span>Level {{ $role->level }} / {{ $role->name }}</span>
                                        <span class="access-muted">{{ $role->users_count }} users / {{ $role->permissions_count }} permissions</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>

            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Teams</h2>
                <div class="space-y-2">
                    @foreach($teams as $team)
                        <div class="border rounded p-3" style="border-color:#dfe7e2">
                            <div class="font-semibold">{{ $team->name }}</div>
                            <div class="access-muted text-sm">Manager: {{ $team->manager?->name ?? 'Unassigned' }} / Unit: {{ $team->unit?->name ?? 'None' }} / Vertical: {{ $team->vertical?->name ?? 'None' }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
