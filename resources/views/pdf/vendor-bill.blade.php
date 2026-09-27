<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TAN90 Bill {{ $submission->invoice_number ?: 'SUB-'.$submission->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172033; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand { font-size: 19px; font-weight: bold; color: #172033; }
        .muted { color: #64748b; }
        .small { font-size: 9px; }
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
        .pill { color: #166534; background: #dcfce7; padding: 4px 7px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width:68%; border:0; padding:0;">
                <table style="width:auto; border:0;"><tr>
                    <td style="border:0; padding:0 10px 0 0; vertical-align:middle;"><img class="logo" src="{{ public_path('images/tan90-logo.png') }}" alt="Tan90 logo"></td>
                    <td style="border:0; padding:0; vertical-align:middle;"><div class="brand">TAN90</div>
                    <div class="muted small">TAN90 ERP · Vendor Bill &amp; Dispatch Note</div></td>
                </tr></table>
                <div class="small" style="margin-top:9px;">{{ $po?->shipping_building }} {{ $po?->shipping_street }}<br>{{ $po?->shipping_city }}, {{ $po?->shipping_state }} {{ $po?->shipping_zip }}</div>
            </td>
            <td style="width:32%; border:0; padding:0; text-align:right;">
                <div class="title">Vendor Bill</div>
                <div class="small" style="margin-top:6px;"><strong>Bill No:</strong> {{ $submission->invoice_number ?: 'SUB-'.$submission->id }}<br><strong>Bill Date:</strong> {{ $submission->invoice_date?->format('d M Y') ?? $submission->created_at?->format('d M Y') }}<br><strong>PO No:</strong> {{ $submission->po_number }}</div>
                <img class="qr" src="{{ $qrDataUri }}" style="margin-top:7px;"><br>
                <span class="muted small">Scan to open secure bill</span>
            </td>
        </tr>
    </table>

    <div class="section">Vendor / delivery</div>
    <table><tr>
        <td class="box" style="width:50%;"><strong>{{ $submission->vendor_name }}</strong><br>{{ $vendor?->address_building }} {{ $vendor?->address_street }}<br>{{ $vendor?->address_city }}, {{ $vendor?->address_state }} {{ $vendor?->address_zip }}<br>GSTIN: {{ $vendor?->gst_number ?? '—' }}</td>
        <td class="box" style="width:50%;"><strong>Deliver against PO</strong><br>{{ $po?->subject ?? '—' }}<br>Expected arrival: {{ $submission->expected_arrival_at?->format('d M Y, H:i') ?? '—' }}<br>Vehicle: {{ $submission->vehicle_number ?: '—' }}</td>
    </tr></table>

    <div class="section">Bill details</div>
    <table>
        <thead><tr><th style="width:7%;">#</th><th>Product / material</th><th style="width:17%;">Qty</th><th style="width:17%;">UOM</th><th style="width:22%;" class="right">Amount</th></tr></thead>
        <tbody>
            <tr><td>1</td><td>{{ $submission->material ?: ($po?->primaryLine()?->product ?? '—') }}</td><td>{{ number_format((float) ($submission->invoice_qty ?? 0), 3) }}</td><td>{{ $po?->primaryLine()?->uom ?? '—' }}</td><td class="right">{{ $submission->invoice_amount !== null ? '₹'.number_format((float) $submission->invoice_amount, 2) : '—' }}</td></tr>
        </tbody>
    </table>

    <table style="margin-top:10px;"><tr><td style="width:65%; border:0;"></td><td class="right total">Bill total</td><td class="right total">{{ $submission->invoice_amount !== null ? '₹'.number_format((float) $submission->invoice_amount, 2) : '—' }}</td></tr></table>

    <div class="section">Dispatch and document status</div>
    <table>
        <tr><td style="width:28%;"><strong>Dispatch status</strong></td><td><span class="pill">{{ ucfirst(str_replace('_', ' ', $submission->status)) }} · Dispatched</span></td></tr>
        <tr><td><strong>Dispatched at</strong></td><td>{{ $submission->dispatched_at?->format('d M Y, H:i') ?? '—' }}</td></tr>
        <tr><td><strong>Expected arrival</strong></td><td>{{ $submission->expected_arrival_at?->format('d M Y, H:i') ?? '—' }}</td></tr>
        <tr><td><strong>Attached documents</strong></td><td>{{ collect(['Invoice' => $submission->invoice_document_path, 'E-way Bill' => $submission->eway_bill_document_path, 'LR/POD' => $submission->lr_pod_document_path])->filter()->keys()->implode(', ') ?: 'None' }}</td></tr>
        <tr><td><strong>PO due date</strong></td><td>{{ $po?->due_date?->format('d M Y') ?? '—' }}</td></tr>
    </table>

    <div class="section">Traceability</div>
    <div class="box small">This bill is generated from the vendor submission saved in TAN90 ERP. The PO number links this bill to vendor acknowledgement, gate entry, unloading, QC, GRN, putaway and finance status.<br><br><span class="muted">Secure share link valid until {{ now()->addDay()->format('d M Y, H:i') }} · {{ $shareUrl }}</span></div>
    <div class="footer">Generated from TAN90 ERP. This document is a system-generated vendor bill/dispatch reference; the uploaded source invoice remains the legal tax document.</div>
</body>
</html>
