<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-5xl mx-auto space-y-5">
        <header class="access-top p-5 sm:p-6">
            <div class="text-sm access-muted">Production / Order creation</div>
            <h1 class="text-2xl sm:text-3xl font-bold">Create Production Work Order</h1>
            <p class="access-muted text-sm mt-2">Start from an approved production plan. The product's approved definition, exact FG SKU, site and warehouses bind this order to its real Inventory recipe and stock.</p>
            <div class="flex flex-wrap gap-2 mt-3"><a class="access-btn" href="{{ route('forge.plans.index') }}">Production plans</a><a class="access-btn" href="{{ route('forge.workorders.index') }}">Work-order list</a><a class="access-btn" href="{{ route('flow.orders.index') }}">Customer orders</a></div>
        </header>
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>@endif
        @include('forge.work-orders.partials.create-form')
    </div>
    @include('forge.work-orders.partials.create-script')
</x-app-layout>
