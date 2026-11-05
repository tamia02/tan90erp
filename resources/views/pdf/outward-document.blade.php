<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        // $kind: challan, transfer, or invoice.
        $isChallan = $kind === 'challan';
        $isInvoice = $kind === 'invoice';
        $isTaxable = ! $isChallan;
        $documentTitle = $isChallan ? 'Delivery Challan' : ($isInvoice ? 'Tax Invoice' : 'Transfer Order');
        $accent = $isChallan ? '#0b6b5e' : ($isInvoice ? '#167347' : '#3c3c3c');
        $m = fn ($n) => \App\Support\PdfDocument::money($n);
        $q = fn ($n) => number_format((float) $n, 2);
        $from = $shipment->fromLocation;
        $to = $shipment->toLocation;
        $issuer = \App\Support\PdfDocument::issuer($from);
        $skus = \App\Models\SkuMaster::whereIn('sku', $shipment->lines->map(fn ($l) => $l->item?->sku)->filter())->get(['sku', 'product_code', 'zoho_hsn'])->keyBy('sku');
        $number = $isChallan ? $shipment->challan_number : ($shipment->invoice_number ?: $shipment->number);
        $movement = ['transfer' => 'Internal Movement', 'job_work' => 'Job Work', 'sales' => 'Supply'][$shipment->purpose] ?? $shipment->purposeLabel();
        $toLines = $to ? \App\Support\PdfDocument::addressLines($to) : array_filter([$shipment->to_address, $shipment->to_state, 'India']);
        $fromLines = $from ? \App\Support\PdfDocument::addressLines($from) : array_filter([$shipment->from_address, $shipment->from_state, 'India']);
        $placeCode = substr((string) $shipment->to_gstin, 0, 2);
    @endphp
    <title>{{ $documentTitle }} {{ $number }}</title>
    <x-pdf.zoho-style :accent="$accent" :title-color="$isChallan ? $accent : '#111'" />
</head>
<body>
    <x-pdf.zoho-header :issuer="$issuer" :title="strtoupper($documentTitle)" :number="($isChallan ? 'Delivery Challan# ' : ($isInvoice ? 'Invoice# ' : 'TransferOrder# ')).$number">
        @unless ($isChallan)
            <table class="meta" style="margin-top:18px;">
                <tr><td class="k">{{ $isInvoice ? 'Invoice Date' : 'Date' }}</td><td class="v strong">{{ ($isInvoice ? $shipment->invoice_date : null)?->format('d-m-Y') ?? $shipment->created_at->format('d-m-Y') }}</td></tr>
                @unless ($isInvoice)<tr><td class="k">Date of Transfer</td><td class="v strong">{{ ($shipment->dispatched_at ?? $shipment->created_at)->format('d-m-Y') }}</td></tr>@endunless
                <tr><td class="k">Created By</td><td class="v strong">{{ $shipment->creator?->name ?? '—' }}</td></tr>
            </table>
        @endunless
    </x-pdf.zoho-header>

    @if ($isChallan)
        <table style="margin-top:16px;">
            <tr>
                <td style="width:55%; padding-top:12px;">
                    <div class="label">Deliver To</div>
                    <div class="strong">{{ $shipment->to_name }}</div>
                    @foreach ($toLines as $line){{ $line }}<br>@endforeach
                    @if ($shipment->to_gstin){{ $shipment->to_gstin }}@endif
                </td>
                <td style="width:45%;">
                    <table class="meta">
                        <tr><td class="k">Challan Date :</td><td class="v">{{ $shipment->created_at->format('d-m-Y') }}</td></tr>
                        <tr><td class="k">Ref# :</td><td class="v">{{ $shipment->sales_order_number ?: $shipment->number }}</td></tr>
                        <tr><td class="k">Challan Type :</td><td class="v">{{ $shipment->purpose === 'job_work' ? 'Job Work' : 'Others' }}</td></tr>
                        <tr><td class="k">Movement Type :</td><td class="v">{{ $movement }}</td></tr>
                        @if ($shipment->ewb_number)<tr><td class="k">E-Way Bill :</td><td class="v">{{ $shipment->ewb_number }}</td></tr>@endif
                        @if ($shipment->vehicle_number)<tr><td class="k">Vehicle :</td><td class="v">{{ $shipment->vehicle_number }}</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>
        <p style="margin-top:12px;">Place Of Supply: {{ $shipment->to_state }}{{ $placeCode ? " ({$placeCode})" : '' }}</p>
    @else
        <table style="margin-top:16px;">
            <tr>
                <td style="width:48%; border-right:1px solid #bbb; padding-right:10px;">
                    <div class="strong">Source Location</div>
                    {{ $shipment->from_name }}<br>
                    @foreach ($fromLines as $line){{ $line }}<br>@endforeach
                    @if ($shipment->from_gstin)GSTIN {{ $shipment->from_gstin }}@endif
                </td>
                <td style="width:52%; padding-left:16px;">
                    <div class="strong">Destination Location</div>
                    {{ $shipment->to_name }}<br>
                    @foreach ($toLines as $line){{ $line }}<br>@endforeach
                    @if ($shipment->to_gstin)GSTIN {{ $shipment->to_gstin }}@endif
                </td>
            </tr>
        </table>
    @endif

    <table class="items" style="margin-top:14px;">
        <thead><tr><th style="width:4%;">#</th><th>Item &amp; Description</th><th style="width:10%;">HSN/SAC</th><th class="r" style="width:9%;">Qty</th><th class="r" style="width:10%;">Rate</th>@if ($isTaxable)<th class="r" style="width:7%;">GST %</th><th class="r" style="width:9%;">CGST</th><th class="r" style="width:9%;">SGST</th><th class="r" style="width:9%;">IGST</th>@endif<th class="r" style="width:13%;">Amount</th></tr></thead>
        <tbody>
            @foreach ($shipment->lines as $i => $line)
                @php $sm = $skus[$line->item?->sku] ?? null; $code = $line->item?->code ?: $sm?->product_code; @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->item?->documentName() }}@if ($code)<br><span class="muted">SKU : {{ $code }}</span>@endif @if ($line->packagingType)<br><span class="muted">Package: {{ $line->packagingType->name }} × {{ $line->packaging_quantity }}@if ($line->box_size) · {{ $line->box_size }}@endif</span>@endif</td>
                    <td>{{ $sm?->zoho_hsn }}</td>
                    <td class="r">{{ $q($line->qty) }}<br><span class="muted">{{ $line->item?->unit }}</span></td>
                    <td class="r">{{ $m($line->rate) }}</td>
                    @if ($isTaxable)
                        <td class="r">{{ rtrim(rtrim(number_format((float) $line->gst_rate, 2), '0'), '.') }}%</td>
                        <td class="r">{{ $m($line->cgst_amount) }}</td>
                        <td class="r">{{ $m($line->sgst_amount) }}</td>
                        <td class="r">{{ $m($line->igst_amount) }}</td>
                    @endif
                    <td class="r">{{ $m($isTaxable ? $line->total_amount : $line->value) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top:4px;">
        @if ($isTaxable)
            <tr><td style="width:50%;"></td><td class="r">Taxable Sub Total</td><td class="r" style="width:120px;">{{ $m($shipment->subtotal_value) }}</td></tr>
            @if ((float) $shipment->cgstTotal() > 0)<tr><td></td><td class="r">CGST</td><td class="r">{{ $m($shipment->cgstTotal()) }}</td></tr><tr><td></td><td class="r">SGST</td><td class="r">{{ $m($shipment->sgstTotal()) }}</td></tr>@endif
            @if ((float) $shipment->igstTotal() > 0)<tr><td></td><td class="r">IGST</td><td class="r">{{ $m($shipment->igstTotal()) }}</td></tr>@endif
            <tr><td></td><td class="r">Total GST</td><td class="r">{{ $m($shipment->tax_total) }}</td></tr>
        @else
            <tr><td style="width:55%;"></td><td class="r">Sub Total</td><td class="r" style="width:110px;">{{ $m($shipment->total_value) }}</td></tr>
        @endif
        <tr><td style="width:55%;"></td><td class="r grand" style="{{ $isChallan ? 'background:#e6f4f1;' : '' }}">Total</td><td class="r grand" style="width:110px; {{ $isChallan ? 'background:#e6f4f1;' : '' }}">₹{{ $m($shipment->total_value) }}</td></tr>
        <tr><td></td><td class="r" style="color:#555;">Total In Words:</td><td class="strong" style="font-style:italic;">{{ \App\Support\PdfDocument::amountInWords((float) $shipment->total_value) }}</td></tr>
    </table>

    @if ($isChallan)
        <div class="section">Terms &amp; Conditions</div>
        <div class="small">
            @if ($shipment->notes){!! nl2br(e($shipment->notes)) !!}<br>@endif
            @if ($shipment->purpose !== 'sales')Own location for business purpose - No supply under GST.<br>Own goods. Not for Sale.@else Goods sent under Delivery Challan.@endif
        </div>
    @else
        <div class="section">Reason</div>
        <div class="small">{{ $shipment->notes ?: $shipment->purposeLabel() }}</div>
    @endif

    <x-pdf.signature />
</body>
</html>
