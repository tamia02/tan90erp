<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-4xl mx-auto space-y-5">
        <div class="access-top p-5">
            <a href="{{ route('access.activity.index') }}" class="text-sm access-muted">&larr; Back to Activity</a>
            <div class="text-sm access-muted mt-2">Access Control / Activity / {{ $log->created_at?->format('d M Y, H:i') }}</div>
            <h1 class="text-2xl font-bold">{{ str($log->action)->replace(['.', '_'], ' ')->headline()->toString() }}</h1>
            <p class="access-muted text-sm mt-1">
                By {{ $actorName ?? 'System' }}
                @if($targetLabel) on {{ $targetLabel }} #{{ $log->target_id }} @endif
                @if($log->reason) &middot; {{ $log->reason }} @endif
            </p>
        </div>

        @if($targetFields)
            <div class="access-card p-5">
                <h2 class="font-bold mb-3">{{ $targetLabel }} — current record</h2>
                <div class="access-grid">
                    @foreach($targetFields as $f)
                        <div>
                            <div class="text-xs access-muted uppercase">{{ $f['label'] }}</div>
                            <div class="text-sm mt-0.5 break-words">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($afterFields)
            <div class="access-card p-5">
                <h2 class="font-bold mb-3">What was submitted</h2>
                <div class="access-grid">
                    @foreach($afterFields as $f)
                        <div>
                            <div class="text-xs access-muted uppercase">{{ $f['label'] }}</div>
                            <div class="text-sm mt-0.5 break-words">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($beforeFields)
            <div class="access-card p-5">
                <h2 class="font-bold mb-3">Value before this change</h2>
                <div class="access-grid">
                    @foreach($beforeFields as $f)
                        <div>
                            <div class="text-xs access-muted uppercase">{{ $f['label'] }}</div>
                            <div class="text-sm mt-0.5 break-words">{{ $f['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if(! $targetFields && ! $afterFields && ! $beforeFields)
            <div class="access-card p-5 text-sm access-muted">
                No structured record is linked to this activity entry (it may have been removed since).
            </div>
        @endif
    </div>
</x-app-layout>
