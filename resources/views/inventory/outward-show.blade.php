<x-app-layout>
    @php
        $shipmentOpen = in_array($shipment->status, ['documents_pending', 'ready'], true);
        $status = match ($shipment->status) {
            'ready' => 'Documents complete · waiting for truck exit',
            'dispatched' => 'Dispatched · stock movement posted',
            'cancelled' => 'Cancelled · reservation released',
            default => 'Documents pending · stock reserved',
        };
    @endphp
    <div class="max-w-6xl mx-auto space-y-5">
        <a href="{{ route('inventory.dispatch') }}" wire:navigate class="text-sm font-semibold" style="color:var(--brand);">← Outward shipments</a>

        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase font-semibold tracking-wide" style="color:var(--brand);">Outward shipment</p>
                    <h1 class="text-2xl font-bold mt-1" style="color:var(--text-primary);">{{ $shipment->number }}</h1>
                    <p class="mt-1">{{ $shipment->purposeLabel() }} · {{ $shipment->documentLabel() }} · {{ $shipment->created_at?->format('d M Y, H:i') }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-semibold" style="background:{{ $shipmentOpen ? 'var(--status-warning-bg)' : 'var(--surface-2)' }};color:{{ $shipmentOpen ? 'var(--status-warning)' : 'var(--text-secondary)' }};">{{ $status }}</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-5 text-sm">
                <div><div class="text-xs" style="color:var(--text-muted);">From</div><strong>{{ $shipment->from_name }}</strong></div>
                <div><div class="text-xs" style="color:var(--text-muted);">To</div><strong>{{ $shipment->to_name }}</strong></div>
                <div><div class="text-xs" style="color:var(--text-muted);">Created by</div><strong>{{ $shipment->creator?->name ?: '—' }}</strong></div>
                <div><div class="text-xs" style="color:var(--text-muted);">Gate entry / truck</div>
                    @if ($shipment->gateEntry)
                        <a href="{{ route('gate-entries.show', $shipment->gateEntry) }}" wire:navigate class="font-semibold hover:underline" style="color:var(--brand);">{{ $shipment->gateEntry->gate_no }} · {{ $shipment->gateEntry->vehicle_number }} · {{ \App\Support\GateStatusLabels::label($shipment->gateEntry->status) }}</a>
                    @else
                        <strong>Waiting for truck arrival</strong>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border p-4 mt-5 text-sm" style="border-color:var(--border);background:var(--surface-1);">
                <strong>How stock changes</strong>
                <p class="mt-1" style="color:var(--text-secondary);">Creating this shipment reserves its quantities, so available stock is reduced immediately. On-hand stock is posted as dispatched only after the Guard confirms the truck has exited. Completing loading alone leaves the quantity reserved while the truck is still on site.</p>
            </div>
        </section>

        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <h2 class="text-lg font-semibold mb-3">Items and GST</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm min-w-[700px]">
                    <thead><tr class="text-left" style="color:var(--text-muted);"><th class="py-2 pr-3">Item</th><th class="py-2 pr-3 text-right">Qty</th><th class="py-2 pr-3 text-right">Rate</th><th class="py-2 pr-3 text-right">GST</th><th class="py-2 pr-3 text-right">Tax</th><th class="py-2 text-right">Line total</th></tr></thead>
                    <tbody>
                        @foreach ($shipment->lines as $line)
                            <tr style="border-top:1px solid var(--border);">
                                <td class="py-2 pr-3">{{ $line->item?->documentName() ?: '—' }}@if ($line->packagingType)<div class="text-xs" style="color:var(--text-muted);">{{ $line->packagingType->name }} × {{ $line->packaging_quantity }}{{ $line->box_size ? ' · '.$line->box_size : '' }}</div>@endif</td>
                                <td class="py-2 pr-3 text-right">{{ rtrim(rtrim((string)$line->qty, '0'), '.') }} {{ $line->item?->unit }}</td>
                                <td class="py-2 pr-3 text-right">₹{{ number_format((float)$line->rate, 2) }}</td>
                                <td class="py-2 pr-3 text-right">{{ rtrim(rtrim(number_format((float)$line->gst_rate, 2), '0'), '.') }}%</td>
                                <td class="py-2 pr-3 text-right">₹{{ number_format((float)$line->tax_amount, 2) }}</td>
                                <td class="py-2 text-right font-semibold">₹{{ number_format((float)$line->total_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr style="border-top:1px solid var(--border);"><td colspan="4" class="py-2 text-right">Taxable amount</td><td colspan="2" class="py-2 text-right">₹{{ number_format((float)$shipment->subtotal_value, 2) }}</td></tr><tr><td colspan="4" class="py-1 text-right">Total GST</td><td colspan="2" class="py-1 text-right">₹{{ number_format((float)$shipment->tax_total, 2) }}</td></tr><tr><td colspan="4" class="py-2 text-right font-bold">Grand total</td><td colspan="2" class="py-2 text-right font-bold">₹{{ number_format((float)$shipment->total_value, 2) }}</td></tr></tfoot>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border p-5" style="background:var(--surface-3);border-color:var(--border);">
            <h2 class="text-lg font-semibold mb-1">Documents</h2>
            <p class="text-sm mb-3" style="color:var(--text-secondary);">The uploaded official invoice is stored separately from the system-generated preview. Uploading or updating metadata does not replace the uploaded file unless a new invoice file is selected.</p>
            <div class="flex flex-wrap gap-3 text-sm">
                @if ($shipment->document_type === 'tax_invoice')<a href="{{ route('inventory.outward.tax-invoice', $shipment) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open system-generated invoice preview</a>@endif
                @if ($shipment->invoice_file)<a href="{{ route('inventory.outward.file', [$shipment, 'invoice']) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open uploaded official invoice</a>@endif
                @if ($shipment->irn_file)<a href="{{ route('inventory.outward.file', [$shipment, 'irn']) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open uploaded IRN / QR document</a>@endif
                @if ($shipment->ewb_file)<a href="{{ route('inventory.outward.file', [$shipment, 'ewb']) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open uploaded E-Way Bill</a>@endif
                @if ($shipment->challan_number)<a href="{{ route('inventory.outward.challan', $shipment) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open Delivery Challan</a>@endif
                @if ($shipment->purpose === 'transfer')<a href="{{ route('inventory.outward.transfer-order', $shipment) }}" target="_blank" class="font-semibold hover:underline" style="color:var(--brand);">Open Transfer Order</a>@endif
            </div>
            @if ($shipmentOpen)
                <a href="{{ route('inventory.dispatch', ['documents' => $shipment->id]) }}#outward-shipment-{{ $shipment->id }}" class="inline-flex rounded-lg px-4 py-2 mt-4 text-sm font-semibold text-white" style="background:var(--brand);">Upload / manage documents</a>
            @endif
        </section>
    </div>
</x-app-layout>
