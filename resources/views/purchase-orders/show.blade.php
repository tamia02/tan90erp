<x-app-layout>
    <div class="max-w-7xl mx-auto space-y-5">
        <a href="{{ route('admin.po') }}" wire:navigate class="text-sm font-semibold" style="color:var(--brand);">← Purchase orders</a>
        <section x-data="{ showPdf: false }" class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wide font-semibold" style="color:var(--brand);">Purchase order</p>
                    <h1 class="text-2xl font-bold mt-1" style="color:var(--text-primary);">{{ $purchaseOrder->po_number }}</h1>
                    <p class="mt-1" style="color:var(--text-secondary);">{{ $purchaseOrder->vendor_name }} · {{ $purchaseOrder->subject ?: 'No subject' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-semibold" style="background:var(--brand-bg);color:var(--brand);">{{ $lifecycleStatus }}</span>
                    <button type="button" @click="showPdf = !showPdf" :aria-expanded="showPdf.toString()" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--brand);"><span x-text="showPdf ? 'Hide PDF View' : 'Show PDF View'">Show PDF View</span></button>
                    <a href="{{ route('admin.po.pdf', $purchaseOrder->po_number) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color:var(--border);color:var(--brand);">Download PDF</a>
                </div>
            </div>
            <div x-cloak x-show="showPdf" x-transition class="mt-5 rounded-xl border bg-white p-1" style="border-color:var(--border);">
                <iframe title="Purchase order PDF preview" src="{{ route('admin.po.preview', $purchaseOrder->po_number) }}" class="w-full rounded-lg" style="height:min(78vh, 1000px);" loading="lazy"></iframe>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 text-sm">
                @foreach (['PO date' => $purchaseOrder->po_date?->format('d M Y'), 'Due date' => $purchaseOrder->due_date?->format('d M Y'), 'Nature' => $purchaseOrder->purchase_nature, 'Category' => $purchaseOrder->material_type, 'Payment terms' => $purchaseOrder->payment_terms, 'Vendor acknowledgement' => $purchaseOrder->acknowledgement ? ($purchaseOrder->acknowledgement->accepted ? 'Accepted' : 'Declined') : 'Awaiting vendor'] as $label => $value)
                    <div><div class="text-xs font-semibold" style="color:var(--text-muted);">{{ $label }}</div><div class="mt-1" style="color:var(--text-primary);">{{ $value ?: '—' }}</div></div>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <h2 class="text-lg font-semibold mb-3" style="color:var(--text-primary);">Items and quantities</h2>
            <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left" style="color:var(--text-muted);"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3">HSN/SAC</th><th class="py-2 pr-3">GST</th><th class="py-2 pr-3 text-right">Ordered</th><th class="py-2 pr-3 text-right">Rate</th><th class="py-2 text-right">Line total</th></tr></thead><tbody>
                @foreach ($purchaseOrder->lines as $line)
                    <tr style="border-top:1px solid var(--border);"><td class="py-3 pr-3 font-medium">{{ $line->documentProduct() }}</td><td class="py-3 pr-3">{{ $line->hsn_sac ?: '—' }}</td><td class="py-3 pr-3">{{ $line->gst_rate }}%</td><td class="py-3 pr-3 text-right">{{ \App\Support\IndianNumberFormatter::quantity($line->quantity) }} {{ $line->uom ?: 'PCS' }}</td><td class="py-3 pr-3 text-right">₹{{ \App\Support\IndianNumberFormatter::format($line->list_price) }}</td><td class="py-3 text-right">₹{{ \App\Support\IndianNumberFormatter::format((float)$line->quantity * (float)$line->list_price + (float)$line->tax) }}</td></tr>
                @endforeach
            </tbody></table></div>
            <div class="mt-4 text-right text-lg font-bold">Grand total: ₹{{ \App\Support\IndianNumberFormatter::format($purchaseOrder->grandTotal()) }}</div>
        </section>

        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <h2 class="text-lg font-semibold mb-3" style="color:var(--text-primary);">Receipt and putaway history</h2>
            @forelse ($gates as $gate)
                <article class="rounded-xl border p-4 mb-3" style="border-color:var(--border);background:var(--surface-2);">
                    <div class="flex flex-wrap justify-between gap-2"><strong>{{ $gate->gate_no }}</strong><span class="text-sm font-semibold">{{ $gate->putaway_completed_at ? 'Put away done' : ($gate->grnRecord?->posted ? 'GRN posted · Putaway pending' : \App\Support\GateStatusLabels::label($gate->status)) }}</span></div>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-3 text-sm"><span>Bill qty: {{ $gate->invoice_qty ?? '—' }}</span><span>Physically received: {{ $gate->qcResult?->physical_received ?? '—' }}</span><span>Accepted: {{ $gate->grnRecord?->accepted_qty ?? $gate->qcResult?->accepted_qty ?? '—' }}</span><span>GRN: {{ $gate->grnRecord?->posted ? 'Posted' : 'Pending' }}</span><span>Putaway: {{ $gate->putaway_completed_at?->format('d M Y, H:i') ?? 'Pending' }}</span></div>
                </article>
            @empty
                <p class="text-sm" style="color:var(--text-muted);">No inward gate or receipt records are linked to this PO yet.</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
