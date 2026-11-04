<x-app-layout>
    @include('access-control._style')
    <div class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5">
            <h1 class="text-2xl font-bold">Zoho Sales / Packages / Invoices</h1>
            <p class="text-sm access-muted">Records imported from Zoho Inventory</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @foreach(['salesorder' => 'Sales Orders', 'package' => 'Packages', 'invoice' => 'Invoices'] as $key => $label)
                <a class="access-btn {{ $type === $key ? 'access-btn-primary' : '' }}" href="{{ route('flow.zoho-sales', ['type' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="get" action="{{ route('flow.zoho-sales') }}" class="flex flex-wrap gap-3 items-center">
            <input type="hidden" name="type" value="{{ $type }}">
            <input class="access-input" name="q" value="{{ $search ?? '' }}" maxlength="255" aria-label="Search number or customer" placeholder="Search number or customer">
            <button class="access-btn" type="submit">Search</button>
            <span class="text-sm access-muted">{{ number_format($documents->total()) }} records</span>
        </form>
        <div class="access-card p-4 overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead><tr><th class="p-2">Number</th><th class="p-2">Sales Order</th><th class="p-2">Customer</th><th class="p-2">Date</th><th class="p-2">Status</th><th class="p-2">Amount</th><th class="p-2">Synced</th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr class="border-t">
                        <td class="p-2">{{ $document->number }}</td><td class="p-2">{{ $document->salesorder_number ?: $document->salesorder_id ?: '—' }}</td>
                        <td class="p-2">{{ $document->customer_name }}</td><td class="p-2">{{ $document->document_date?->format('d M Y') }}</td><td class="p-2">{{ $document->status }}</td>
                        <td class="p-2">{{ $document->total === null ? '—' : ($document->payload['currency_code'] ?? '').' '.number_format((float) $document->total, 2) }}</td><td class="p-2">{{ $document->synced_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-4">No {{ $type }} records imported yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    </div>
</x-app-layout>
