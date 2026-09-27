<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TAN90 GRN {{ $gate->po_number ?: $gate->gate_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172033; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #cbd5e1; padding: 7px; text-align: left; }
        th { background: #eff6ff; color: #1e3a8a; }
        .header td { border: 0; vertical-align: top; }
        .brand { font-size: 20px; font-weight: bold; }
        .title { font-size: 16px; font-weight: bold; color: #2563eb; text-align: right; }
        .muted { color: #64748b; }
        .section { margin-top: 18px; margin-bottom: 6px; font-size: 11px; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        .good { color: #166534; } .bad { color: #b91c1c; }
        .logo { width: 70px; height: 62px; }
    </style>
</head>
@php
    $columnLabels = $columnLabels ?? \App\Support\ReportColumns::GRN;
    $columns = $columns ?? array_keys($columnLabels);
    $reportRows = $reportRows ?? \App\Support\ReportColumns::grnRows($gate, $grn);
@endphp
<body>
    <table class="header"><tr>
        <td style="width:65%;"><img class="logo" src="{{ public_path('images/tan90-logo.png') }}"><div class="brand">TAN90</div><div class="muted">ERP · Goods Receipt Note</div></td>
        <td style="width:35%;"><div class="title">GRN REPORT</div><div style="text-align:right;margin-top:6px;"><strong>PO:</strong> {{ $gate->po_number ?: '—' }}<br><strong>Gate:</strong> {{ $gate->gate_no }}<br><strong>Posted:</strong> {{ $gate->grnRecord?->created_at?->format('d M Y, H:i') ?? '—' }}</div></td>
    </tr></table>
    <div class="section">Receipt details</div>
    <table><tr><td><strong>Vendor</strong><br>{{ $gate->vendor_name ?: '—' }}</td><td><strong>Invoice</strong><br>{{ $gate->invoice_number ?: '—' }}</td><td><strong>Vehicle</strong><br>{{ $gate->vehicle_number ?: '—' }}</td><td><strong>Dock</strong><br>{{ $gate->loading_dock ?: '—' }}</td></tr></table>
    <div class="section">Final QC / GRN quantities by SKU</div>
    <table><thead><tr>@foreach($columns as $column)<th>{{ $columnLabels[$column] }}</th>@endforeach</tr></thead><tbody>
        @forelse ($reportRows as $row)
            <tr>@foreach($columns as $column)<td>{{ in_array($column, ['unit_price'], true) && $row[$column] !== null ? number_format((float)$row[$column], 4) : (in_array($column, ['invoice_amount','accepted_value'], true) && $row[$column] !== null ? number_format((float)$row[$column], 2) : ($row[$column] ?? '—')) }}</td>@endforeach</tr>
        @empty<tr><td colspan="{{ count($columns) }}">No SKU lines are available.</td></tr>@endforelse
    </tbody></table>
    <p class="muted">This vendor-facing report shows the final accepted, rejected, defective, on-hold and missing quantities recorded at GRN closure. GRN posting remains blocked while an on-hold quantity is unresolved.</p>
    <div class="section">Storage and payment basis</div>
    <table><tr><td><strong>Suggested bin</strong><br>{{ $grn->suggested_bin ?: '—' }}</td><td><strong>GRN status</strong><br>{{ $grn->posted ? 'Posted' : 'Pending' }}</td><td><strong>Vendor response</strong><br>{{ $grn->vendor_response ? ucfirst($grn->vendor_response) : 'Pending vendor response' }}</td></tr></table>
    @if ($grn->qc_reasons)<div class="section">QC notes</div><div>{{ $grn->qc_reasons }}</div>@endif
    <p class="muted" style="margin-top:24px;border-top:1px solid #cbd5e1;padding-top:8px;">Generated from TAN90 ERP. GRN is the official accepted-goods basis for finance and vendor reconciliation.</p>
</body>
</html>
