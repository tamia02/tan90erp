<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Access Control / Views</div>
            <h1 class="text-2xl font-bold">Saved View Builder</h1>
            <p class="access-muted text-sm mt-1">A saved view is a preset - which columns, filters and sort order show by default - assigned to a role, a team, or one person.</p>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif

        @if ($canManage)
            <form method="post" action="{{ route('access.views.store') }}" class="access-card p-5 space-y-3">
                @csrf
                <h2 class="font-bold">Create Saved View</h2>
                <div class="grid md:grid-cols-3 gap-3">
                    <input class="access-input" name="name" placeholder="View name (e.g. GRN + QC Focus)" required>
                    <input class="access-input" name="module" placeholder="Module (e.g. GRN)" required>
                    <input class="access-input" name="screen_key" placeholder="Screen key (e.g. grn.register)" required>
                </div>
                <textarea class="access-input" name="description" placeholder="Description (optional)"></textarea>
                <div class="grid md:grid-cols-2 gap-3">
                    <input class="access-input" name="columns" placeholder="Columns, comma separated (e.g. gate_no, vendor_name, status)">
                    <input class="access-input" name="filters" placeholder="Default filters, comma separated (e.g. status:pending_validation)">
                </div>
                <select class="access-input max-w-xs" name="status">
                    <option value="draft">Save as draft</option>
                    <option value="published">Publish immediately</option>
                </select>
                <button class="access-btn access-btn-primary">Create Saved View</button>
            </form>
        @endif

        <div class="access-card overflow-hidden">
            <table class="access-table"><thead><tr><th>Name</th><th>Module / Screen</th><th>Owner</th><th>Status</th><th>Assigned to</th>@if($canManage)<th>Assign / Publish</th>@endif</tr></thead><tbody>
                @foreach($views as $view)
                    @php($viewAssignments = $assignments->get($view->id, collect()))
                    <tr>
                        <td><strong>{{ $view->name }}</strong><div class="access-muted text-xs">{{ $view->key }}</div>@if($view->description)<div class="access-muted text-xs mt-1">{{ $view->description }}</div>@endif</td>
                        <td>{{ $view->module }} / {{ $view->screen_key }}</td>
                        <td>{{ $view->owner?->name ?? 'System' }} <span class="access-muted text-xs">(L{{ $view->owner_level }})</span></td>
                        <td><span class="access-chip" style="{{ $view->status === 'published' ? '' : 'background:#fdf3e7;color:#9a5b00' }}">{{ ucfirst($view->status) }}</span></td>
                        <td>
                            @forelse($viewAssignments as $a)
                                <div class="text-xs access-muted">{{ ucfirst($a->assignable_type) }} #{{ $a->assignable_id }}@if($a->is_default) &middot; default @endif@if($a->can_personalise) &middot; personalisable @endif</div>
                            @empty
                                <span class="access-muted text-xs">Not assigned</span>
                            @endforelse
                        </td>
                        @if($canManage)
                            <td>
                                <form method="post" action="{{ route('access.views.assign', $view) }}" class="flex flex-wrap gap-1.5 items-center mb-2">
                                    @csrf
                                    <input type="hidden" name="assignable_type" value="role" x-target-type>
                                    <select class="access-input" name="assignable_id" style="width:190px" onchange="this.form.querySelector('[x-target-type]').value = this.options[this.selectedIndex].dataset.type">
                                        <optgroup label="Roles">@foreach($roles as $role)<option value="{{ $role->id }}" data-type="role">{{ $role->name }}</option>@endforeach</optgroup>
                                        <optgroup label="Teams">@foreach($teams as $team)<option value="{{ $team->id }}" data-type="team">{{ $team->name }}</option>@endforeach</optgroup>
                                        <optgroup label="People">@foreach($people as $person)<option value="{{ $person->id }}" data-type="user">{{ $person->name }}</option>@endforeach</optgroup>
                                    </select>
                                    <label class="text-xs flex items-center gap-1"><input type="checkbox" name="is_default" value="1"> Default</label>
                                    <button class="access-btn" style="padding:.35rem .6rem;font-size:.75rem">Assign</button>
                                </form>
                                <form method="post" action="{{ route('access.views.publish', $view) }}">
                                    @csrf
                                    <button class="access-btn" style="padding:.35rem .6rem;font-size:.75rem">{{ $view->status === 'published' ? 'Unpublish' : 'Publish' }}</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody></table>
        </div>
        {{ $views->links() }}
    </div>
</x-app-layout>
