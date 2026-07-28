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
                    <label class="text-xs font-bold access-muted uppercase">Role</label>
                    <select class="access-input mt-1" name="role_id" required>
                        <option value="">Select a role</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>
                                {{ $role->name }} (Level {{ $role->hierarchy_level ?? $role->level }})
                            </option>
                        @endforeach
                    </select>
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
