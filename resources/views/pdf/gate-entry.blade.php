<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $entry->gate_no }}</title>
    <style>
        /* dompdf has limited CSS support (no custom properties, no flex/grid) --
           deliberately plain, table-based layout rather than reusing the app's
           var(--token) design system, which dompdf can't render at all. */
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .eyebrow { font-size: 10px; color: #4f46e5; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 6px; }
        .meta { color: #6b7280; font-size: 10px; margin: 0 0 18px; }
        .section-title { font-size: 13px; font-weight: bold; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { width: 25%; padding: 5px 8px; vertical-align: top; border: 1px solid #e5e7eb; }
        table.fields td .label { display: block; font-size: 9px; color: #6b7280; text-transform: uppercase; }
        table.fields td .value { display: block; margin-top: 2px; font-size: 11px; word-wrap: break-word; }
        table.fields.single td { width: 100%; }
        table.fields.items td { width: auto; }
        .issue { border: 1px solid #e5e7eb; padding: 6px 8px; margin-bottom: 6px; }
        .issue.open { background: #fef2f2; border-color: #fecaca; }
        .issue-title { font-weight: bold; }
        .issue-meta { color: #6b7280; font-size: 9px; text-transform: capitalize; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #d1d5db; color: #9ca3af; font-size: 9px; }
        .logo { width: 76px; height: 68px; }
    </style>
</head>
<body>
    <table style="width:100%; border-collapse:collapse;"><tr><td style="border:0; padding:0;"><table style="width:auto; border:0;"><tr><td style="border:0; padding:0 10px 0 0; vertical-align:middle;"><img class="logo" src="{{ public_path('images/tan90-logo.png') }}" alt="Tan90 logo"></td><td style="border:0; padding:0; vertical-align:middle;"><div class="eyebrow">Tan90 ERP &middot; Gate Pass</div><h1>{{ $entry->gate_no }}</h1><div class="meta">Generated {{ now()->format('d M Y, H:i') }} by {{ auth()->user()->name }}</div></td></tr></table></td><td style="border:0; padding:0; text-align:right;"><img src="{{ $qrDataUri }}" style="width:78px; height:78px;"><div class="small">Scan to view gate pass</div></td></tr></table>

    <div class="section-title">Gate entry — submitted details</div>
    <table class="fields">
        <tr>
            @foreach ($fields as $i => $f)
                <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                @if (($i + 1) % 4 === 0 && ! $loop->last)
                    </tr><tr>
                @endif
            @endforeach
        </tr>
    </table>
    <p><strong>Scanned bill:</strong> {{ $entry->bill_document_path ? 'File attached' : 'No bill file uploaded' }}</p>

    @if ($entry->entry_type === 'inward' && auth()->user()->role !== \App\Enums\Role::Guard)
        <div class="section-title">Purchase order</div>
        @if ($purchaseOrder)
            <table class="fields single">
                @foreach ($purchaseOrderFields as $i => $f)
                    <tr><td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td></tr>
                @endforeach
            </table>
        @else
            <p>PO {{ $entry->po_number ?: '(not recorded)' }} could not be matched to a purchase order.</p>
        @endif
        @if (! empty($invoiceLines))
            <div class="section-title">Shipment lines on this invoice</div>
            <table class="fields items">
                <thead><tr><td><span class="label">Item / SKU</span></td><td><span class="label">HSN/SAC</span></td><td><span class="label">PO Qty</span></td><td><span class="label">Invoice Qty</span></td><td><span class="label">UOM</span></td>@if (auth()->user()->role !== \App\Enums\Role::Vendor)<td><span class="label">PO Unit Rate</span></td>@endif</tr></thead>
                @foreach ($invoiceLines as $line)
                    <tr><td>{{ $line['item'] ?: 'Unspecified item' }}@if ($line['line_details'])<br><small>{{ $line['line_details'] }}</small>@endif</td><td>{{ $line['hsn_sac'] ?: 'Not set on PO' }}</td><td>{{ $line['ordered_qty'] ?? 'Not recorded' }}</td><td>{{ $line['invoice_qty'] ?? 'Not recorded' }}</td><td>{{ $line['uom'] ?: 'Not specified' }}</td>@if (auth()->user()->role !== \App\Enums\Role::Vendor)<td>{{ filled($line['unit_rate']) ? '₹'.number_format((float) $line['unit_rate'], 2) : 'Not recorded' }}</td>@endif</tr>
                @endforeach
            </table>
            <p>Invoice total: {{ $entry->invoice_amount !== null ? '₹'.number_format((float) $entry->invoice_amount, 2) : 'Not recorded' }} (this gate entry only).</p>
        @endif
        @if ($vendorSubmission)
            <div class="section-title">Vendor invoice &amp; dispatch submission</div>
            <table class="fields single">
                @foreach ($submissionFields as $f)
                    <tr><td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td></tr>
                @endforeach
            </table>
            <p>Attached vendor documents: {{ collect($submissionDocuments)->pluck('label')->implode(', ') ?: 'None' }}</p>
        @else
            <p>No vendor submission matches the exact invoice {{ $entry->invoice_number ?: '(not recorded)' }}.</p>
        @endif
    @endif

    @if (! empty($unloadingFields))
        <div class="section-title">Physical unloading</div>
        <table class="fields single">
            @foreach ($unloadingFields as $f)
                <tr><td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td></tr>
            @endforeach
        </table>
    @endif

    @if ($issues->isNotEmpty())
        <div class="section-title">Validation issues</div>
        @foreach ($issues as $issue)
            <div class="issue {{ $issue->status === 'open' ? 'open' : '' }}">
                <span class="issue-title">{{ $issue->title }}</span>
                <span class="issue-meta"> &middot; {{ $issue->severity }} &middot; {{ $issue->status }}</span>
                <div>{{ $issue->description }}</div>
            </div>
        @endforeach
    @endif

    @if (! empty($qcFields))
        <div class="section-title">QC result</div>
        <table class="fields">
            <tr>
                @foreach ($qcFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
        @if ($qcEvidenceImages->isNotEmpty())
            <p><strong>QC evidence images:</strong> {{ $qcEvidenceImages->values()->map(fn ($image, $index) => ($image->image_type ?: 'QC evidence').' '.($index + 1))->implode(', ') }}</p>
        @endif
    @endif

    @if (! empty($grnFields))
        <div class="section-title">GRN record</div>
        <table class="fields">
            <tr>
                @foreach ($grnFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @if (! empty($financeFields))
        <div class="section-title">Finance record</div>
        <table class="fields">
            <tr>
                @foreach ($financeFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    <div class="footer">Tan90 ERP &middot; {{ $entry->gate_no }} &middot; Confidential internal record</div>
</body>
</html>
