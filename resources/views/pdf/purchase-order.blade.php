<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $po->po_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #172033; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand { font-size: 19px; font-weight: bold; color: #172033; }
        .muted { color: #64748b; }
        .small { font-size: 8px; }
        .title { font-size: 16px; font-weight: bold; color: #2563eb; text-transform: uppercase; letter-spacing: 1px; }
        .section { margin-top: 18px; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; font-size: 11px; font-weight: bold; }
        .box { border: 1px solid #cbd5e1; padding: 8px; }
        th { background: #eff6ff; color: #1e3a8a; font-weight: bold; text-align: left; }
        td, th { border: 1px solid #cbd5e1; padding: 7px; }
        .right { text-align: right; }
        .qr { width: 92px; height: 92px; }
        .logo { width: 88px; height: 78px; }
        .total { font-size: 13px; font-weight: bold; color: #172033; }
        .footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #cbd5e1; color: #64748b; font-size: 8px; }
        @if (($po->pdf_template ?? 'standard') === 'compact')
        @page { margin: 20px; }
        body { font-size: 8px; }
        td, th { padding: 4px; }
        .section { margin-top: 11px; padding-bottom: 3px; }
        .box { padding: 5px; }
        .footer { margin-top: 13px; }
        @else
        @page { margin: 30px; }
        @endif
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width:68%; border:0; padding:0;">
                <table style="width:auto; border:0;"><tr>
                    <td style="border:0; padding:0 10px 0 0; vertical-align:middle;"><img class="logo" src="{{ public_path('images/tan90-logo.png') }}" alt="Tan90 logo"></td>
                    <td style="border:0; padding:0; vertical-align:middle;"><div class="brand">TAN90</div>
                    <div class="muted small">TAN90 ERP · Procurement &amp; Supply Chain</div></td>
                </tr></table>
                    <div class="small" style="margin-top:9px;">Procurement &amp; Supply Chain · Place of supply: {{ $po->shipping_state ?? '—' }} · Registered state: {{ $companyState ?? 'Not configured' }}</div>
            </td>
            <td style="width:32%; border:0; padding:0; text-align:right;">
                <div class="title">Purchase Order</div>
                <div class="small" style="margin-top:6px;"><strong>PO No:</strong> {{ $po->po_number }}<br><strong>PO Date:</strong> {{ $po->po_date?->format('d M Y') ?? '—' }}<br><strong>Due Date:</strong> {{ $po->due_date?->format('d M Y') ?? '—' }}</div>
                <img class="qr" src="{{ $qrDataUri }}" style="margin-top:7px;"><br>
                <span class="muted small">Scan to open PO status</span>
            </td>
        </tr>
    </table>

    <div class="section">Supplier, billing and delivery</div>
    <table><tr>
        <td class="box" style="width:34%;"><strong>Supplier</strong><br>{{ $po->vendor_name }}<br>{{ $vendor?->address_building }} {{ $vendor?->address_street }}<br>{{ $vendor?->address_city }}, {{ $vendor?->address_state }} {{ $vendor?->address_zip }}<br>GSTIN: {{ $vendor?->gst_number ?? '—' }}</td>
        <td class="box" style="width:33%;"><strong>Billing address</strong><br>{{ $po->billing_building }} {{ $po->billing_street }}<br>{{ $po->billing_city }}, {{ $po->billing_state }} {{ $po->billing_zip }}<br>{{ $po->billing_country ?? 'India' }}</td>
        <td class="box" style="width:33%;"><strong>Delivery location</strong><br>{{ $po->shipping_building }} {{ $po->shipping_street }}<br>{{ $po->shipping_city }}, {{ $po->shipping_state }} {{ $po->shipping_zip }}<br>{{ $po->shipping_country ?? 'India' }} · Contact: {{ $po->contact_name ?? 'Store Manager' }}</td>
    </tr></table>

    @if (!empty($po->header_custom_fields))
        <div class="section">Additional PO details</div>
        <table><tr>
            @foreach ($po->header_custom_fields as $label => $value)
                <td><strong>{{ $label }}</strong><br>{{ $value }}</td>
            @endforeach
        </tr></table>
    @endif

    <div class="section">Order lines</div>
    <table>
        <thead><tr><th>#</th><th>Product / TAN90 SKU</th><th>HSN / SAC</th><th>Qty</th><th>UOM</th><th class="right">Unit price</th><th class="right">Taxable value</th><th class="right">GST breakup</th></tr></thead>
        <tbody>
            @foreach ($po->lines as $index => $line)
                @php $tax = $lineTaxes[$line->id] ?? ['taxable' => (float) $line->quantity * (float) $line->list_price, 'cgst' => 0, 'sgst' => 0, 'igst' => 0]; @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->product }}
                        @if ($line->custom_fields)
                            <br><span class="muted small">{{ collect($line->custom_fields)->map(fn ($value, $label) => $label.': '.$value)->implode(' · ') }}</span>
                        @endif
                    </td>
                    <td>{{ $line->hsn_sac ?? '—' }}</td>
                    <td>{{ number_format((float) $line->quantity, 3) }}</td>
                    <td>{{ $line->uom ?? '—' }}</td>
                    <td class="right">₹{{ number_format((float) $line->list_price, 2) }}</td>
                    <td class="right">₹{{ number_format($tax['taxable'], 2) }}</td>
                    <td class="right">{{ number_format((float) $tax['rate'], 2) }}%<br>
                        @if ($tax['igst'] > 0) IGST ₹{{ number_format($tax['igst'], 2) }}
                        @elseif ($tax['cgst'] > 0 || $tax['sgst'] > 0) CGST ₹{{ number_format($tax['cgst'], 2) }}<br>SGST ₹{{ number_format($tax['sgst'], 2) }}
                        @else — @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php
        $cgstTotal = $lineTaxes->sum('cgst');
        $sgstTotal = $lineTaxes->sum('sgst');
        $igstTotal = $lineTaxes->sum('igst');
    @endphp
    <table style="margin-top:10px;">
        <tr><td style="width:62%; border:0;"></td><td class="right">Taxable subtotal</td><td class="right">₹{{ number_format($po->subTotal(), 2) }}</td></tr>
        @if ((float) $po->discount > 0)<tr><td style="border:0;"></td><td class="right">PO discount</td><td class="right">− ₹{{ number_format((float) $po->discount, 2) }}</td></tr>@endif
        @if ($cgstTotal > 0)<tr><td style="border:0;"></td><td class="right">CGST</td><td class="right">₹{{ number_format($cgstTotal, 2) }}</td></tr>@endif
        @if ($sgstTotal > 0)<tr><td style="border:0;"></td><td class="right">SGST</td><td class="right">₹{{ number_format($sgstTotal, 2) }}</td></tr>@endif
        @if ($igstTotal > 0)<tr><td style="border:0;"></td><td class="right">IGST</td><td class="right">₹{{ number_format($igstTotal, 2) }}</td></tr>@endif
        @if ((float) $po->adjustment !== 0)<tr><td style="border:0;"></td><td class="right">Adjustment</td><td class="right">₹{{ number_format((float) $po->adjustment, 2) }}</td></tr>@endif
        <tr><td style="border:0;"></td><td class="right total">Grand total</td><td class="right total">₹{{ number_format($po->grandTotal(), 2) }}</td></tr>
        <tr><td style="border:0;"></td><td colspan="2" class="right small"><strong>Amount in words:</strong> {{ $totalInWords }}</td></tr>
    </table>
    <div class="section">Terms &amp; traceability</div>
    <div class="box small"><strong>Material type:</strong> {{ $po->material_type ?? '—' }}<br>{{ $po->terms_and_conditions ?? 'Supply as per approved PO, agreed specifications and delivery schedule.' }}<br>
        Payment terms: {{ $po->payment_terms ?? '—' }} · Purchase nature: {{ $po->purchase_nature ?? 'Material' }} · Warehouse: {{ $po->warehouse_location ?? '—' }}<br>
        @if ($po->reverse_charge)<strong>Reverse charge applicable — tax to be accounted for by recipient.</strong><br>@endif
        <br><span class="muted">PO status: {{ $po->status }} · Release timestamp: {{ $po->released_at?->format('d M Y, H:i') ?? 'Not released' }} · Scan payload: {{ $lookupUrl }}</span></div>
    @if (!empty($po->attachments))
        <div class="section">Attachments</div>
        <div class="box small">@foreach ($po->attachments as $attachment){{ $attachment['name'] ?? 'PO attachment' }}@if(!$loop->last) · @endif @endforeach</div>
    @endif
    <div class="footer">Generated from TAN90 ERP. This document is the vendor-facing PO reference; every dispatch and receipt is linked by PO number.</div>
</body>
</html>
