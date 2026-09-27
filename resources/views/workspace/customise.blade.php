<x-app-layout>
    @include('access-control._style')
    <div class="workspace-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex justify-between items-center">
            <div>
                <div class="text-sm access-muted">Workspace / Customise</div>
                <h1 class="text-2xl font-bold">Customise Your Dashboard</h1>
            </div>
            <div class="flex gap-2">
                <a class="access-btn" href="{{ route('workspace.index') }}">Back to Command Center</a>
                <button form="layoutForm" class="access-btn access-btn-primary">Save</button>
            </div>
        </div>

        @if (session('status'))
            <div class="access-card p-3 text-sm">{{ session('status') }}</div>
        @endif

        @php($pinned = $widgets->filter(fn ($c) => $c['locks']['locked'] || $c['locks']['mandatory']))
        @php($movable = $widgets->reject(fn ($c) => $c['locks']['locked'] || $c['locks']['mandatory'])->sortBy(fn ($c) => $c['layout']['y'] ?? 999)->values())

        <form id="layoutForm" method="post" action="{{ route('workspace.save') }}">
            @csrf

            @if ($pinned->isNotEmpty())
                <p class="access-muted text-sm mb-2">Pinned by your role — position and visibility are set by your role template, not by you.</p>
                <div class="workspace-grid mb-6">
                    @foreach ($pinned as $card)
                        @php($widget = $card['widget'])
                        <article class="workspace-card workspace-tile p-4 workspace-tile-locked" style="--w:{{ $card['layout']['w'] ?? $widget->default_w }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="access-muted text-sm">{{ $widget->module }}</div>
                                <span class="access-chip" title="{{ $card['locks']['locked'] ? 'Position locked by your role' : 'Always shown for your role' }}">
                                    {{ $card['locks']['locked'] ? 'Locked' : 'Pinned' }}
                                </span>
                            </div>
                            <h2 class="font-bold mt-1">{{ $widget->title }}</h2>
                            <p class="access-muted text-sm mt-1">{{ $widget->description }}</p>
                            <input type="hidden" name="layouts[{{ $widget->key }}][widget_key]" value="{{ $widget->key }}">
                            <input type="hidden" name="layouts[{{ $widget->key }}][x]" value="{{ $card['layout']['x'] ?? 0 }}">
                            <input type="hidden" name="layouts[{{ $widget->key }}][y]" value="{{ $card['layout']['y'] ?? 0 }}">
                            <input type="hidden" name="layouts[{{ $widget->key }}][w]" value="{{ $card['layout']['w'] ?? $widget->default_w }}">
                            <input type="hidden" name="layouts[{{ $widget->key }}][h]" value="{{ $card['layout']['h'] ?? $widget->default_h }}">
                            <input type="hidden" name="layouts[{{ $widget->key }}][visible]" value="1">
                        </article>
                    @endforeach
                </div>
            @endif

            <p class="access-muted text-sm mb-2">Your widgets — drag a card to reorder, use the controls to resize or hide.</p>
            <div class="workspace-grid" id="workspaceGrid">
                @foreach ($movable as $i => $card)
                    @php($widget = $card['widget'])
                    @php($layout = $card['layout'])
                    <article class="workspace-card workspace-tile p-4" draggable="true" data-widget-key="{{ $widget->key }}" style="--w:{{ $layout['w'] ?? $widget->default_w }}">
                        <div class="flex items-start justify-between gap-2">
                            <span class="workspace-drag-handle" title="Drag to reorder">⠿</span>
                            <label class="text-xs flex items-center gap-1">
                                <input type="checkbox" class="workspace-visible-toggle" name="layouts[{{ $widget->key }}][visible]" value="1" @checked($layout['visible'] ?? true)>
                                Visible
                            </label>
                        </div>
                        <div class="access-muted text-sm mt-1">{{ $widget->module }}</div>
                        <h2 class="font-bold">{{ $widget->title }}</h2>
                        <p class="access-muted text-sm">{{ $widget->description }}</p>
                        <label class="block mt-2 text-xs">
                            <span class="font-semibold access-muted">Width</span>
                            <select class="access-input workspace-width-select" name="layouts[{{ $widget->key }}][w]">
                                @foreach ([12 => 'Full', 8 => 'Wide', 6 => 'Half', 4 => 'Third', 3 => 'Quarter'] as $width => $label)
                                    <option value="{{ $width }}" @selected(($layout['w'] ?? $widget->default_w) == $width)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <input type="hidden" name="layouts[{{ $widget->key }}][widget_key]" value="{{ $widget->key }}">
                        <input type="hidden" class="workspace-x-input" name="layouts[{{ $widget->key }}][x]" value="{{ $layout['x'] ?? 0 }}">
                        <input type="hidden" class="workspace-y-input" name="layouts[{{ $widget->key }}][y]" value="{{ $layout['y'] ?? $i }}">
                        <input type="hidden" class="workspace-h-input" name="layouts[{{ $widget->key }}][h]" value="{{ $layout['h'] ?? $widget->default_h }}">
                    </article>
                @endforeach
            </div>
        </form>
    </div>

    <script>
        (function () {
            const grid = document.getElementById('workspaceGrid');
            if (!grid) return;

            let dragged = null;

            grid.querySelectorAll('[draggable="true"]').forEach((card) => {
                card.addEventListener('dragstart', () => {
                    dragged = card;
                    card.classList.add('workspace-dragging');
                });

                card.addEventListener('dragend', () => {
                    card.classList.remove('workspace-dragging');
                    dragged = null;
                    renumber();
                });

                card.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    if (!dragged || dragged === card) return;

                    const rect = card.getBoundingClientRect();
                    const before = (e.clientX - rect.left) < rect.width / 2;
                    card.parentNode.insertBefore(dragged, before ? card : card.nextSibling);
                });
            });

            // Widths only ever change via the select, so keep the -w custom
            // property in sync for the CSS grid-column span to update live.
            grid.querySelectorAll('.workspace-width-select').forEach((select) => {
                select.addEventListener('change', () => {
                    select.closest('.workspace-tile').style.setProperty('--w', select.value);
                });
            });

            function renumber() {
                grid.querySelectorAll('.workspace-tile').forEach((card, index) => {
                    const yInput = card.querySelector('.workspace-y-input');
                    if (yInput) yInput.value = index;
                });
            }

            renumber();
        })();
    </script>
</x-app-layout>
