<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <header class="access-top p-5"><div class="text-sm access-muted">Forge / Setup</div><h1 class="text-2xl font-bold">Approved FG mappings by site</h1><p class="access-muted text-sm mt-1">Sales, work orders and FG bins use the exact mapped SKU and warehouses.</p></header>
        @if(session('status'))<div class="access-card p-3">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-red-700">{{ $errors->first() }}</div>@endif
        <form class="access-card p-5 space-y-3" method="post" action="{{ route('forge.mappings.store') }}">@csrf
            <h2 class="font-bold">Approve or update mapping</h2>
            <div class="access-grid">
                <select class="access-input" name="site_id" required><option value="">Site</option>@foreach($locations as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach</select>
                <select class="access-input" name="finished_good_id" required><option value="">Approved FG master</option>@foreach($goods as $good)<option value="{{ $good->id }}">{{ $good->code }} · {{ $good->name }}</option>@endforeach</select>
                <select class="access-input" name="inventory_fg_item_id" required><option value="">Exact Inventory FG SKU</option>@foreach($items->where('item_type','fg') as $item)<option value="{{ $item->id }}">{{ $item->code }} · {{ $item->safeDisplayName() }} ({{ $item->unit }})</option>@endforeach</select>
                <select class="access-input" name="rm_warehouse_id" required><option value="">RM/PM warehouse</option>@foreach($warehouses->where('warehouse_type', 'Raw Material') as $wh)<option value="{{ $wh->id }}" data-site="{{ $wh->tan90_location_id }}" data-type="{{ $wh->warehouse_type }}">{{ $wh->code }} · {{ $wh->name }}</option>@endforeach</select>
                <select class="access-input" name="fg_warehouse_id" required><option value="">FG warehouse</option>@foreach($warehouses->where('warehouse_type', 'Finished Goods') as $wh)<option value="{{ $wh->id }}" data-site="{{ $wh->tan90_location_id }}" data-type="{{ $wh->warehouse_type }}">{{ $wh->code }} · {{ $wh->name }}</option>@endforeach</select>
                <label class="block text-sm font-semibold">PCM formula item (optional)
                    <select class="access-input mt-1 w-full" name="reusable_pcm_item_id"><option value="">No PCM item reference</option>@foreach($items->where('item_type','rm') as $item)<option value="{{ $item->id }}">{{ $item->code }} · {{ $item->safeDisplayName() }}</option>@endforeach</select>
                    <span class="access-muted block text-xs mt-1">Reference for the PCM unit and FIFO handling of this finished product. Recovered mixed solution is reused on its work order and never stored here.</span>
                </label>
            </div><button class="access-btn access-btn-primary">Approve mapping</button>
        </form>
        <div class="access-card p-5 overflow-x-auto"><table class="access-table"><thead><tr><th>Site</th><th>FG master</th><th>Inventory SKU</th><th>RM WH</th><th>FG WH</th><th>PCM reference</th><th>Status</th></tr></thead><tbody>@foreach($mappings as $m)<tr><td>{{ $m->site_id }}</td><td>{{ $m->finished_good_id }}</td><td>{{ $m->inventory_fg_item_id }}</td><td>{{ $m->rm_warehouse_id }}</td><td>{{ $m->fg_warehouse_id }}</td><td>{{ $m->reusable_pcm_item_id ?? '—' }}</td><td>{{ $m->status }}</td></tr>@endforeach</tbody></table></div>
    </div>
    <script>document.querySelector('[name="site_id"]')?.addEventListener('change', e => {for(const name of ['rm_warehouse_id','fg_warehouse_id']) {const select=document.querySelector(`[name="${name}"]`); const type=name==='rm_warehouse_id'?'Raw Material':'Finished Goods'; select.value=''; select.querySelectorAll('option[data-site]').forEach(o=>o.hidden=o.dataset.site!==e.target.value||o.dataset.type!==type);}});</script>
</x-app-layout>
