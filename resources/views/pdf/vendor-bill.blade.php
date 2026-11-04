<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TAN90 Bill {{ $submission->invoice_number ?: 'SUB-'.$submission->id }}</title>
    {{-- Zoho Books "BILL" layout: logo + company, BILL title, Bill From vendor, order / dates, dark item header, GST split, total, signature. --}}
    <x-pdf.zoho-style />
</head>
@php
    $m = fn ($n) => \App\Support\PdfDocument::money($n);
    $issuer ??= \App\Support\PdfDocument::issuer();
    $billLines ??= [];
    $taxRows ??= [];
    $billTotal ??= (float) $submission->invoice_amount;
    $subTotal ??= $billTotal;
    $billNo = $submission->invoice_number ?: 'SUB-'.$submission->id;
    $billDate = $submission->invoice_date ?? $submission->created_at;
@endphp
<body>
    <x-pdf.zoho-header :issuer="$issuer" title="BILL" :number="'Bill# '.$billNo">
        <div style="text-align:right; margin-top:14px;"><span class="strong" style="font-size:8.5px;">Total</span><br><span class="strong" style="font-size:12px;">₹{{ $m($billTotal) }}</span></div>
        <div style="text-align:right; margin-top:6px;"><img src="{{ $qrDataUri }}" style="width:62px; height:62px;"><br><span class="muted">Scan to open secure bill</span></div>
    </x-pdf.zoho-header>

    <table style="margin-top:16px;">
        <tr>
            <td style="width:55%;">
                <div class="label">Bill From</div>
                <div class="strong">{{ $submission->vendor_name }}</div>
                @if ($vendor?->address_building || $vendor?->address_street){{ trim($vendor?->address_building.' '.$vendor?->address_street) }}<br>@endif
                @if ($vendor?->address_city){{ $vendor->address_city }}<br>@endif
                @if ($vendor?->address_zip || $vendor?->address_state){{ trim($vendor?->address_zip.' '.$vendor?->address_state) }}<br>@endif
                India<br>
                @if ($vendor?->gst_number && $vendor->gst_number !== 'ZOHO-N/A')GSTIN {{ $vendor->gst_number }}@endif
            </td>
            <td style="width:45%;">
                <table class="meta">
                    <tr><td class="k">Order Number :</td><td class="v">{{ $submission->po_number ?: '—' }}</td></tr>
                    <tr><td class="k">Bill Date :</td><td class="v">{{ $billDate?->format('d-m-Y') ?? '—' }}</td></tr>
                    <tr><td class="k">Due Date :</td><td class="v">{{ ($submission->invoice_due_date ?? $po?->due_date)?->format('d-m-Y') ?? '—' }}</td></tr>
                    @if ($po?->payment_terms)<tr><td class="k">Terms :</td><td class="v">{{ $po->payment_terms }}</td></tr>@endif
                </table>
                @if ($po)
                    <div class="label" style="margin-top:12px;">Bill To · TAN90</div>
                    <div class="strong">{{ $po->billing_building ?: config('company.name') }}</div>
                    @if ($po->billing_street){{ $po->billing_street }}<br>@endif
                    {{ trim($po->billing_city.' '.$po->billing_state.' '.$po->billing_zip) }}<br>
                    {{ $po->billing_country ?: 'India' }}

                    <div class="label" style="margin-top:12px;">Delivery Address</div>
                    @if ($po->warehouse_location)<div class="strong">{{ $po->warehouse_location }}</div>@endif
                    @if ($po->shipping_building || $po->shipping_street){{ trim($po->shipping_building.' '.$po->shipping_street) }}<br>@endif
                    {{ trim($po->shipping_city.' '.$po->shipping_state.' '.$po->shipping_zip) }}<br>
                    {{ $po->shipping_country ?: 'India' }}
                @endif
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:16px;">
        <thead><tr><th style="width:5%;">#</th><th>Item &amp; Description</th><th style="width:12%;">HSN/SAC</th><th class="r" style="width:12%;">Qty</th><th class="r" style="width:12%;">Rate</th><th class="r" style="width:15%;">Amount</th></tr></thead>
        <tbody>
            @foreach ($billLines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['name'] }}@if ($line['code'])<br><span class="muted">SKU : {{ $line['code'] }}</span>@endif</td>
                    <td>{{ $line['hsn'] }}</td>
                    <td class="r">{{ number_format((float) $line['qty'], 2) }}@if ($line['uom'])<br><span class="muted">{{ $line['uom'] }}</span>@endif</td>
                    <td class="r">{{ $m($line['rate']) }}</td>
                    <td class="r">{{ $m($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top:4px;">
        <tr><td style="width:55%;"></td><td class="r">Sub Total</td><td class="r" style="width:110px;">{{ $m($subTotal) }}</td></tr>
        @foreach ($taxRows as $row)<tr><td></td><td class="r">{{ $row['label'] }}</td><td class="r">{{ $m($row['amount']) }}</td></tr>@endforeach
        <tr><td></td><td class="r grand band">Total</td><td class="r grand band">₹{{ $m($billTotal) }}</td></tr>
        <tr><td></td><td colspan="2" class="r muted">{{ \App\Support\PdfDocument::amountInWords($billTotal) }}</td></tr>
    </table>

    <div class="section">Dispatch details</div>
    <table class="meta" style="width:70%;">
        <tr><td style="width:35%;">Dispatch status</td><td>{{ ucfirst(str_replace('_', ' ', $submission->status)) }}</td></tr>
        <tr><td>Dispatched at</td><td>{{ $submission->dispatched_at?->format('d-m-Y H:i') ?? '—' }}</td></tr>
        <tr><td>Expected arrival</td><td>{{ $submission->expected_arrival_at?->format('d-m-Y H:i') ?? '—' }}</td></tr>
        <tr><td>Vehicle</td><td>{{ $submission->vehicle_number ?: '—' }}</td></tr>
        <tr><td>Attached documents</td><td>{{ collect(['Invoice' => $submission->invoice_document_path, 'E-way Bill' => $submission->eway_bill_document_path, 'LR/POD' => $submission->lr_pod_document_path])->filter()->keys()->implode(', ') ?: 'None' }}</td></tr>
    </table>

    <x-pdf.signature />
    <p class="muted" style="margin-top:14px;">Generated from TAN90 ERP from the vendor's submission. The vendor's uploaded tax invoice remains the legal tax document. Secure link valid until {{ now()->addDay()->format('d-m-Y H:i') }}.</p>
</body>
</html>
