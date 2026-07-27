<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex justify-between items-center">
            <div>
                <div class="text-sm access-muted">Access Control / Dashboard Builder</div>
                <h1 class="text-2xl font-bold">Role Permission Dashboard Builder</h1>
            </div>
            <a class="access-btn" href="{{ route('workspace.index') }}">Preview Workspace</a>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <div class="grid xl:grid-cols-[320px_1fr] gap-4">
            <aside class="access-card p-4">
                <h2 class="font-bold">Templates</h2>
                <div class="mt-3 space-y-2">
                    @forelse($templates as $template)
                        <a class="block border rounded p-3 text-sm" style="border-color:#dfe7e2" href="{{ route('access.dashboard-builder.index', ['template' => $template->id]) }}">
                            <strong>{{ $template->name }}</strong>
                            <span class="block access-muted">{{ ucfirst($template->owner_type) }} #{{ $template->owner_id }} / {{ $template->items_count }} widgets / {{ $template->status }}</span>
                        </a>
                    @empty
                        <p class="access-muted text-sm">No templates yet.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $templates->links() }}</div>
            </aside>

            <form class="access-card p-5 space-y-5" method="post" action="{{ route('access.dashboard-builder.save') }}">
                @csrf
                <input type="hidden" name="template_id" value="{{ $selectedTemplate?->id }}">

                <div class="grid md:grid-cols-4 gap-3">
                    <input class="access-input md:col-span-2" name="name" value="{{ old('name', $selectedTemplate?->name ?? 'Operations Dashboard Template') }}" placeholder="Template name" required>
                    <select class="access-input" name="owner_type" id="ownerType">
                        @foreach(['role' => 'Role template', 'team' => 'Team template', 'user' => 'User template'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('owner_type', $selectedTemplate?->owner_type ?? 'role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select class="access-input" name="status">
                        <option value="draft" @selected(old('status', $selectedTemplate?->status ?? 'draft') === 'draft')>Draft</option>
                        <option value="published" @selected(old('status', $selectedTemplate?->status) === 'published')>Published</option>
                    </select>
                </div>

                <div class="grid md:grid-cols-3 gap-3">
                    <select class="access-input owner-select" data-owner="role" name="owner_id">
                        @foreach($roles as $role)<option value="{{ $role->id }}" @selected(old('owner_id', $selectedTemplate?->owner_id) == $role->id)>{{ $role->name }} / Level {{ $role->level }}</option>@endforeach
                    </select>
                    <select class="access-input owner-select hidden" data-owner="team" disabled>
                        @foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('owner_id', $selectedTemplate?->owner_id) == $team->id)>{{ $team->name }}</option>@endforeach
                    </select>
                    <select class="access-input owner-select hidden" data-owner="user" disabled>
                        @foreach($users as $user)<option value="{{ $user->id }}" @selected(old('owner_id', $selectedTemplate?->owner_id) == $user->id)>{{ $user->name }} / {{ $user->email }}</option>@endforeach
                    </select>
                </div>

                <div class="space-y-3">
                    @php($templateItems = $selectedTemplate?->items?->keyBy('widget_key') ?? collect())
                    @foreach($widgets as $i => $widget)
                        @php($item = $templateItems->get($widget->key))
                        <section class="dashboard-builder-row">
                            <div class="flex items-center gap-3 min-w-0">
                                <input type="hidden" name="items[{{ $i }}][widget_key]" value="{{ $widget->key }}">
                                <button type="button" class="access-icon-btn" title="Add widget">+</button>
                                <div class="access-widget-icon">+</div>
                                <div class="min-w-0">
                                    <h3 class="font-bold truncate">{{ $widget->title }}</h3>
                                    <p class="access-muted text-sm truncate">{{ $widget->description }}</p>
                                </div>
                            </div>
                            <label>
                                <span>Width</span>
                                <select class="access-input" name="items[{{ $i }}][w]">
                                    @foreach([12 => 'Full', 8 => 'Wide', 6 => 'Half', 4 => 'Third', 3 => 'Quarter'] as $width => $label)
                                        <option value="{{ $width }}" @selected(($item?->w ?? $widget->default_w) == $width)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Visual</span>
                                <select class="access-input" name="items[{{ $i }}][visual]">
                                    @foreach(['stat', 'table', 'list', 'timeline', 'progress', 'pie', 'donut', 'bar', 'line', 'area', 'heatmap', 'funnel', 'depth'] as $visual)
                                        <option value="{{ $visual }}" @selected(($item?->config_json['visual'] ?? 'stat') === $visual)>{{ ucfirst($visual === 'depth' ? '2.5D depth' : $visual) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Custom title</span>
                                <input class="access-input" name="items[{{ $i }}][title]" value="{{ $item?->config_json['title'] ?? $widget->title }}">
                            </label>
                            <div class="dashboard-builder-checks">
                                <label><input type="checkbox" name="items[{{ $i }}][visible]" value="1" @checked($item?->visible ?? true)> Visible</label>
                                <label><input type="checkbox" name="items[{{ $i }}][mandatory]" value="1" @checked($item?->mandatory)> Mandatory</label>
                                <label><input type="checkbox" name="items[{{ $i }}][position_locked]" value="1" @checked($item?->position_locked)> Position lock</label>
                                <label><input type="checkbox" name="items[{{ $i }}][size_locked]" value="1" @checked($item?->size_locked)> Size lock</label>
                            </div>
                            <input type="hidden" name="items[{{ $i }}][h]" value="{{ $item?->h ?? $widget->default_h }}">
                        </section>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2">
                    <a class="access-btn" href="{{ route('access.dashboard-builder.index') }}">New Template</a>
                    <button class="access-btn access-btn-primary">Save Builder</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const ownerType = document.getElementById('ownerType');
        const ownerSelects = document.querySelectorAll('.owner-select');
        function syncOwnerSelects() {
            ownerSelects.forEach((select) => {
                const active = select.dataset.owner === ownerType.value;
                select.classList.toggle('hidden', !active);
                select.disabled = !active;
                if (active) select.name = 'owner_id';
                else select.removeAttribute('name');
            });
        }
        ownerType.addEventListener('change', syncOwnerSelects);
        syncOwnerSelects();
    </script>
</x-app-layout>
