<x-app-layout>
    @include('access-control._style')
    <div class="workspace-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex justify-between items-center"><div><div class="text-sm access-muted">Workspace / Builder</div><h1 class="text-2xl font-bold">Customise Workspace</h1></div><div class="flex gap-2"><button class="access-btn" type="button" onclick="history.back()">Preview</button><button form="layoutForm" class="access-btn access-btn-primary">Publish</button></div></div>
        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        <div class="grid lg:grid-cols-[260px_1fr_260px] gap-4">
            <aside class="access-card p-4"><h2 class="font-bold">Widget Library</h2><input class="access-input mt-3" placeholder="Search widgets"><div class="mt-3 space-y-2">@foreach($widgets as $card)<div class="border rounded p-2 text-sm" style="border-color:#dfe7e2">{{ $card['widget']->title }}<div class="access-muted text-xs">{{ $card['widget']->module }}</div></div>@endforeach</div></aside>
            <form id="layoutForm" method="post" action="{{ route('workspace.save') }}" class="access-card p-4 builder-board">@csrf
                <div class="flex gap-2 mb-4"><button type="button" class="access-btn">Undo</button><button type="button" class="access-btn">Redo</button><button type="button" class="access-btn">Reset</button><span class="access-chip">Desktop</span><span class="access-chip">Tablet</span><span class="access-chip">Mobile</span></div>
                <div class="workspace-grid" id="workspaceGrid">
                    @foreach($widgets as $i => $card)
                        @php($widget=$card['widget'])
                        @php($layout=$layouts->get($widget->key))
                        <article class="workspace-card workspace-tile p-4" draggable="true" style="--w:{{ $layout->w ?? $widget->default_w }}">
                            <input type="hidden" name="layouts[{{ $i }}][widget_key]" value="{{ $widget->key }}">
                            <input type="hidden" name="layouts[{{ $i }}][x]" value="{{ $layout->x ?? 0 }}">
                            <input type="hidden" name="layouts[{{ $i }}][y]" value="{{ $layout->y ?? $i }}">
                            <input type="hidden" name="layouts[{{ $i }}][w]" value="{{ $layout->w ?? $widget->default_w }}">
                            <input type="hidden" name="layouts[{{ $i }}][h]" value="{{ $layout->h ?? $widget->default_h }}">
                            <input type="hidden" name="layouts[{{ $i }}][visible]" value="1">
                            <div class="access-muted text-sm">{{ $widget->module }}</div><h2 class="font-bold">{{ $widget->title }}</h2><p class="access-muted text-sm">{{ $widget->description }}</p>
                        </article>
                    @endforeach
                </div>
            </form>
            <aside class="access-card p-4"><h2 class="font-bold">Properties</h2><p class="access-muted text-sm mt-2">Select a widget to adjust visibility and size. Mandatory or locked inherited widgets cannot be removed or moved by child levels.</p><div class="access-tabs mt-4"><span class="access-tab active">Size</span><span class="access-tab">Variant</span><span class="access-tab">Lock</span></div></aside>
        </div>
    </div>
    <script>
        document.querySelectorAll('#workspaceGrid [draggable=true]').forEach((card)=>{card.addEventListener('dragstart',()=>card.classList.add('opacity-50'));card.addEventListener('dragend',()=>card.classList.remove('opacity-50'));});
    </script>
</x-app-layout>
