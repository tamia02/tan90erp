<x-app-layout>
@include('forge.worker._style')
<div class="pw-shell">
    <header class="pw-head pw-row"><div><div class="pw-kicker">Production · Step {{ array_search($step,array_keys(\App\Services\Forge\WorkerProduction::STEPS),true)+1 }}</div><h1>{{ $title }}{{ $history ? ' History' : '' }}</h1></div><div class="pw-actions"><a class="pw-btn" href="{{ route($history?'forge.modules.show':'forge.modules.history',['step'=>$step]) }}">{{ $history?'Work Queue':'History' }}</a>@if($step==='materials' && $canCreate)<a class="pw-btn pw-primary" href="{{ route('forge.workorders.create') }}">Create Work Order</a>@endif</div></header>
    @include('forge.worker._notices')
    <form method="get" class="pw-card pw-row"><div class="pw-actions">@unless($history)<label class="pw-field">Show<select name="filter" class="pw-input"><option value="pending" @selected($filter==='pending')>Pending</option><option value="in_progress" @selected($filter==='in_progress')>In Progress</option><option value="all" @selected($filter==='all')>All Active</option></select></label>@endunless<label class="pw-field">Search<input class="pw-input" name="search" value="{{ request('search') }}" placeholder="Work order or product"></label><button class="pw-btn">Apply</button></div><span class="pw-badge">{{ $orders->total() }} {{ $history?'records':'jobs' }}</span></form>
    <section class="pw-stack" aria-label="{{ $title }} {{ $history?'history':'work queue' }}">
    @forelse($orders as $wo)
        <article class="pw-card pw-row"><div><strong>{{ $wo->finishedGood?->name }}</strong><p class="pw-muted mt-1">{{ $wo->wo_number }} · {{ $wo->target_qty }} {{ $wo->uom }} · {{ $wo->site?->name ?? 'Site not set' }}</p><div class="pw-actions mt-2"><span class="pw-badge">{{ str($wo->status)->replace('_',' ')->headline() }}</span>@if($wo->operationRuns->contains(fn($r)=>$r->status==='in_progress' && (int)$r->cycle_no===(int)$wo->rework_generation+1))<span class="pw-badge">In Progress</span>@endif</div></div><a class="pw-btn {{ $history?'':'pw-primary' }}" href="{{ route('forge.modules.workorder',['step'=>$step,'workOrder'=>$wo,'history'=>$history?1:null]) }}">{{ $history?'View Record':'Open Job' }}</a></article>
    @empty<div class="pw-card pw-empty">{{ $history?'No completed records match this search.':'No jobs in this queue.' }}</div>@endforelse
    </section>
    {{ $orders->links() }}
    @unless($history)
        <section class="pw-stack" aria-label="Recent completed records">
            <div class="pw-row"><h2>Recent completed records</h2><a class="pw-btn" href="{{ route('forge.modules.history',['step'=>$step]) }}">View full history</a></div>
            @forelse($recentHistory as $record)
                <article class="pw-card pw-row"><div><strong>{{ $record->finishedGood?->name }}</strong><p class="pw-muted mt-1">{{ $record->wo_number }} · {{ $record->target_qty }} {{ $record->uom }} · {{ $record->site?->name ?? 'Site not set' }}</p><span class="pw-badge">{{ str($record->status)->replace('_',' ')->headline() }}</span></div><a class="pw-btn" href="{{ route('forge.modules.workorder',['step'=>$step,'workOrder'=>$record,'history'=>1]) }}">View Record</a></article>
            @empty
                <div class="pw-card pw-empty">Completed work will appear here.</div>
            @endforelse
        </section>
    @endunless
</div>
</x-app-layout>
