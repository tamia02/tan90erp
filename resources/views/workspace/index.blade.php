<x-app-layout>
    @include('access-control._style')
    <div class="workspace-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex justify-between items-center">
            <div>
                <div class="text-sm access-muted">Workspace</div>
                <h1 class="text-2xl font-bold">Command Center</h1>
            </div>
            <a class="access-btn access-btn-primary" href="{{ route('workspace.customise') }}">Customise</a>
        </div>

        @php($queueLabels = ['tasks' => ['My Work', 'open task'], 'approvals' => ['Approval Center', 'pending request'], 'exceptions' => ['Alerts & Exceptions', 'open exception']])
        @if (collect($queues)->filter()->isNotEmpty())
            <div class="access-grid">
                @foreach ($queues as $key => $queue)
                    @continue(! $queue)
                    <a href="{{ route($queue['route']) }}" class="access-card p-4 block">
                        <div class="access-muted text-sm">{{ $queueLabels[$key][0] }}</div>
                        <div class="workspace-metric mt-1">{{ $queue['count'] }}</div>
                        <p class="access-muted text-sm mt-1">{{ Str::plural($queueLabels[$key][1], $queue['count']) }}</p>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($widgets->isEmpty())
            <div class="access-card p-6 text-sm access-muted text-center">
                No widgets are available for your account yet.
            </div>
        @else
            <div class="workspace-grid">
                @foreach ($widgets as $card)
                    @php($widget = $card['widget'])
                    @php($data = $card['data'])
                    @php($w = $card['layout']['w'] ?? $widget->default_w)
                    <article class="workspace-card workspace-tile p-4" style="--w:{{ $w }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="access-muted text-sm">{{ $widget->module }}</div>
                            @if ($card['locks']['mandatory'])
                                <span class="access-chip" title="Always shown for your role">Pinned</span>
                            @endif
                        </div>
                        <h2 class="font-bold mt-1">{{ $widget->title }}</h2>
                        <div class="workspace-metric mt-3">
                            {{ ($data['format'] ?? null) === 'currency' ? '₹'.number_format($data['metric']) : number_format($data['metric']) }}
                        </div>
                        <p class="access-muted text-sm mt-1">{{ $data['caption'] }}</p>
                        @if (isset($data['route']) && Route::has($data['route']))
                            <a class="access-btn mt-4" href="{{ route($data['route'], $data['route_params'] ?? []) }}">Open</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
