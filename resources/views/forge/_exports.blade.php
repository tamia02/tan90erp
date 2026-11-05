@if(app(\App\Services\Access\AccessControlService::class)->can(auth()->user(), $permission))
    <div class="flex flex-wrap gap-2 mt-3">
        <a class="access-btn" href="{{ route($exportRoute, request()->except(['page', 'scope'])) }}">Export Current View</a>
        <a class="access-btn" href="{{ route($exportRoute, ['scope'=>'all']) }}">Export All Data</a>
    </div>
@endif
