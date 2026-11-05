<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <div class="text-sm access-muted">Flow / Customer Orders</div>
            <h1 class="text-2xl font-bold">Customer Orders</h1>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        <form class="access-card p-4 space-y-3" method="post" action="{{ route('flow.orders.store') }}">
            @csrf
            <h2 class="font-bold">Create Customer Order</h2>
            <div class="access-grid">
                <input class="access-input" name="customer_name" placeholder="Customer name" required>
                <select class="access-input" name="site_id" id="order-site-select" required><option value="">Fulfilment site</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                <select class="access-input" name="fg_warehouse_id" id="order-fg-warehouse-select" required><option value="">FG warehouse</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-site="{{ $warehouse->tan90_location_id }}">{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select>
                <input class="access-input" name="destination" placeholder="Destination">
                <select class="access-input" name="temperature_requirement">
                    <option value="">Temperature requirement</option>
                    <option value="ambient">Ambient</option>
                    <option value="chilled">Chilled</option>
                    <option value="frozen">Frozen</option>
                </select>
                <input class="access-input" name="min_shelf_life_days" type="number" min="0" placeholder="Min shelf life (days)">
                <input class="access-input" name="requested_date" type="date">
            </div>
            @if($warehouses->isEmpty())<p class="text-sm text-amber-800">No active Finished Goods warehouse is configured for your site. Ask an administrator to confirm the site warehouse setup before creating an order.</p>@endif
            <button class="access-btn access-btn-primary">Create Order</button>
        </form>
        <script>document.querySelector('[name="site_id"]')?.addEventListener('change', e => { const site=e.target.value; document.querySelectorAll('[name="fg_warehouse_id"] option[data-site]').forEach(o => {o.hidden=o.dataset.site!==site;}); document.querySelector('[name="fg_warehouse_id"]').value=''; });</script>

        <div class="access-card p-5">
            <h2 class="font-bold mb-3">Orders</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Lines</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td>{{ $order->order_number }}</td>
                                <td>{{ $order->customer_name }}</td>
                                <td>{{ $order->lines_count }}</td>
                                <td><span class="access-chip">{{ str($order->status)->replace('_', ' ')->headline() }}</span></td>
                                <td><a class="access-btn" href="{{ route('flow.orders.show', $order) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="access-muted text-center py-6">No customer orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        </div>
    </div>
    <script>
        document.getElementById('order-site-select')?.addEventListener('change', function (event) {
            var select = document.getElementById('order-fg-warehouse-select');
            select.value = '';
            select.querySelectorAll('option[data-site]').forEach(function (option) {
                option.hidden = option.dataset.site !== event.target.value;
            });
        });
    </script>
</x-app-layout>
