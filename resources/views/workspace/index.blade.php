<x-app-layout>
    @include('access-control._style')
    <div class="workspace-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex justify-between items-center"><div><div class="text-sm access-muted">Workspace</div><h1 class="text-2xl font-bold">My Workspace</h1></div><a class="access-btn access-btn-primary" href="{{ route('workspace.customise') }}">Customise</a></div>
        <div class="workspace-grid">
            @foreach($widgets as $card)
                @php($widget=$card['widget'])
                @php($data=$card['data'])
                <article class="workspace-card workspace-tile p-4" style="--w:{{ $widget->default_w }}">
                    <div class="access-muted text-sm">{{ $widget->module }}</div>
                    <h2 class="font-bold">{{ $widget->title }}</h2>
                    <div class="text-3xl font-black mt-4">{{ ($data['format'] ?? null)==='currency' ? '₹'.number_format($data['metric']) : number_format($data['metric']) }}</div>
                    <p class="access-muted text-sm mt-1">{{ $data['caption'] }}</p>
                    @if(isset($data['route']) && Route::has($data['route']))<a class="access-btn mt-4" href="{{ route($data['route'], $data['route_params'] ?? []) }}">Open</a>@endif
                </article>
            @endforeach
        </div>
    </div>
</x-app-layout>
