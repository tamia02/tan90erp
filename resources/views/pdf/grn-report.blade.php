<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>TAN90 GRN {{ $gate->po_number ?: $gate->gate_no }}</title>
    <x-pdf.zoho-style />
    <style>
        .grid td, .grid th { border: 1px solid #b8c0cc; padding: 8px 9px; }
        .grid .k { background: #eef1f5; font-weight: bold; color: #222; width: 22%; }
        .lines th { background: #eef1f5; color: #222; font-weight: bold; border: 1px solid #b8c0cc; padding: 7px 6px; text-align: center; font-size: 8.5px; }
        .lines td { border: 1px solid #b8c0cc; padding: 8px 6px; text-align: center; }
        .lines td.l { text-align: left; }
        .lines .total td { font-weight: bold; }
        .sig th { background: #eef1f5; border: 1px solid #b8c0cc; padding: 8px; font-weight: bold; }
        .sig td { border: 1px solid #b8c0cc; padding: 8px 9px; width: 50%; }
        .head { font-size: 10px; font-weight: bold; color: #222; margin: 16px 0 6px; text-transform: uppercase; }
    </style>
</head>
@php
    // Akhtar's GRN format with the Zoho header / footer used on the other documents.
    $columnLabels = $columnLabels ?? \App\Support\ReportColumns::GRN;
    $columns = $columns ?? array_keys($columnLabels);
    $reportRows = $reportRows ?? \App\Support\ReportColumns::grnRows($gate, $grn);
    $location = \App\Models\Tan90\MasterData\Location::where('name', $gate->location)->orWhere('code', $gate->location)->first();
    $issuer = \App\Support\PdfDocument::issuer($location);
    $po = $gate->po_number ? \App\Models\PurchaseOrder::with('lines')->where('po_number', $gate->po_number)->first() : null;
    $uoms = $po ? $po->lines->mapWithKeys(fn ($l) => [mb_strtolower(trim((string) $l->product)) => $l->uom])->all() : [];
    foreach ((array) $gate->product_lines as $pl) {
        $uoms[mb_strtolower(trim((string) ($pl['sku'] ?? '')))] ??= $pl['uom'] ?? null;
        $uoms[mb_strtolower(trim((string) ($pl['name'] ?? '')))] ??= $pl['uom'] ?? null;
    }
    $sourceLines = $grn->relationLoaded('lines') && $grn->lines->isNotEmpty() ? $grn->lines : collect([$grn]);
    $codes = \App\Models\SkuMaster::whereIn('sku', $sourceLines->pluck('sku')->filter())->whereNotNull('product_code')->pluck('product_code', 'sku');
    $rows = $sourceLines->map(function ($l) use ($gate, $uoms, $codes) {
        $sku = (string) ($l->sku ?: $gate->material);
        $sent = (float) ($l->invoice_qty ?? $l->po_qty ?? 0);
        $received = (float) ($l->physical_received ?? 0);

        return [
            'code' => $codes[$sku] ?? $sku, 'name' => $sku, 'uom' => $uoms[mb_strtolower(trim($sku))] ?? 'Units',
            'sent' => $sent, 'good' => (float) $l->accepted_qty, 'damaged' => (float) $l->rejected_qty + (float) $l->defective_qty,
            'hold' => (float) $l->qc_hold_qty, 'excess' => max(0, $received - $sent), 'short' => (float) $l->missing_qty,
        ];
    });
    $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $receiver = $gate->storeExecVerifiedBy ?? null;
    $unloading = $gate->unloadingRecord;
    $approver = $grn->creator;
    $valueColumns = array_values(array_intersect($columns, ['unit_price', 'invoice_amount', 'accepted_value', 'suggested_bin']));
@endphp
<body>
    <x-pdf.zoho-header :issuer="$issuer" title="Goods Receipt Note" :number="'GRN# '.$grn->number()">
        <div style="text-align:right; margin-top:4px;" class="muted">System Generated GRN</div>
    </x-pdf.zoho-header>

    <table class="grid" style="margin-top:16px;">
        <tr><td class="k">GRN No.</td><td>{{ $grn->number() }}</td><td class="k">GRN Date</td><td>{{ $grn->created_at?->format('d-m-Y') ?? '—' }}</td></tr>
        <tr><td class="k">Request No.</td><td>{{ $gate->gate_no }}</td><td class="k">Receipt Date &amp; Time</td><td>{{ ($unloading?->completed_at ?? $gate->created_at)?->format('d-m-Y h:i A') ?? '—' }}</td></tr>
        <tr><td class="k">Station / Warehouse</td><td>{{ $location?->name ?? $gate->location ?: '—' }}</td><td class="k">Station Code</td><td>{{ $location?->code ?? '—' }}</td></tr>
        <tr><td class="k">Source</td><td>{{ $gate->vendor_name ?: '—' }}@if ($gate->vendor_gst)<br><span class="muted">GSTIN {{ $gate->vendor_gst }}</span>@endif</td><td class="k">PO No. / Date</td><td>{{ $gate->po_number ?: '—' }}{{ $po?->po_date ? ' / '.$po->po_date->format('d-m-Y') : '' }}</td></tr>
        <tr><td class="k">DC / Challan / LR No.</td><td>{{ collect([$gate->invoice_number, $unloading?->pod_lr_ref])->filter()->implode(' / ') ?: '—' }}</td><td class="k">Vehicle</td><td>{{ $gate->vehicle_number ?: '—' }}</td></tr>
    </table>

    <div class="head">Received Line Items</div>
    <table class="lines">
        <thead><tr><th style="width:5%;">Sr.</th><th style="width:15%;">SKU Code</th><th>SKU / Item Description</th><th style="width:7%;">UOM</th><th style="width:9%;">Qty Sent</th><th style="width:9%;">Good Qty</th><th style="width:9%;">Damaged</th><th style="width:8%;">Excess</th><th style="width:8%;">Short</th></tr></thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr><td>{{ $i + 1 }}</td><td class="l">{{ $r['code'] }}</td><td class="l">{{ $r['name'] }}@if ($r['hold'] > 0)<br><span class="muted">On hold: {{ $n($r['hold']) }}</span>@endif</td><td>{{ $r['uom'] }}</td><td>{{ $n($r['sent']) }}</td><td>{{ $n($r['good']) }}</td><td>{{ $n($r['damaged']) }}</td><td>{{ $n($r['excess']) }}</td><td>{{ $n($r['short']) }}</td></tr>
            @empty
                <tr><td colspan="9">No SKU lines are available.</td></tr>
            @endforelse
            <tr class="total"><td></td><td></td><td>TOTAL</td><td></td><td>{{ $n($rows->sum('sent')) }}</td><td>{{ $n($rows->sum('good')) }}</td><td>{{ $n($rows->sum('damaged')) }}</td><td>{{ $n($rows->sum('excess')) }}</td><td>{{ $n($rows->sum('short')) }}</td></tr>
        </tbody>
    </table>

    @if ($valueColumns)
        <div class="head">Value (finance basis)</div>
        <table class="lines">
            <thead><tr><th>SKU</th>@foreach ($valueColumns as $column)<th>{{ $columnLabels[$column] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($reportRows as $row)
                    <tr><td class="l">{{ $row['sku'] ?? '—' }}</td>@foreach ($valueColumns as $column)<td class="r">{{ $row[$column] === null ? '—' : ($column === 'unit_price' ? number_format((float) $row[$column], 4) : ($column === 'suggested_bin' ? $row[$column] : number_format((float) $row[$column], 2))) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if ($grn->qc_reasons)<div class="section"><b>QC notes:</b> {{ $grn->qc_reasons }}</div>@endif
    @if ($grn->vendor_response)<div class="section"><b>Vendor response:</b> {{ ucfirst($grn->vendor_response) }}{{ $grn->vendor_response_note ? ' - '.$grn->vendor_response_note : '' }}</div>@endif

    <div class="head">Digital Signature</div>
    <table class="sig" style="page-break-inside: avoid;">
        <tr><th>RECEIVED BY</th><th>APPROVED BY</th></tr>
        <tr><td>Email ID: {{ $receiver?->email ?? '—' }}</td><td>Email ID: {{ $approver?->email ?? '—' }}</td></tr>
        <tr><td>User / Employee ID: {{ $receiver?->name ?? $unloading?->unloaded_by ?? '—' }}{{ $receiver?->role ? ' ('.($receiver->role->label() ?? $receiver->role->value).')' : '' }}</td><td>User / Employee ID: {{ $approver?->name ?? '—' }}{{ $approver?->role ? ' ('.($approver->role->label() ?? $approver->role->value).')' : '' }}</td></tr>
        <tr><td>Digital Signature: {{ ($receiver || $unloading?->unloaded_by) ? '✓ Verified' : '—' }}</td><td>Digital Signature: {{ ($approver && $grn->posted) ? '✓ Verified' : 'Pending' }}</td></tr>
        <tr><td>Date &amp; Time: {{ ($gate->store_exec_verified_at ?? $unloading?->completed_at)?->format('d-m-Y h:i A') ?? '—' }}</td><td>Date &amp; Time: {{ $grn->created_at?->format('d-m-Y h:i A') ?? '—' }}</td></tr>
    </table>
    <p class="small" style="margin-top:8px;"><b>System Generated:</b> No manual writing is required. Receiver and approver identity, email ID, employee ID, digital signature and date/time are captured automatically from the system.</p>
</body>
</html>
