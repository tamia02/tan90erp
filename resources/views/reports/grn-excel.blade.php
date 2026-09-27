@php
    $columnLabels = $columnLabels ?? \App\Support\ReportColumns::GRN;
    $columns = $columns ?? array_keys($columnLabels);
    $reportRows = $reportRows ?? \App\Support\ReportColumns::grnRows($gate, $grn);
@endphp
<table border="1">
    <tr><th colspan="{{ count($columns) }}">TAN90 ERP — Goods Receipt Note</th></tr>
    <tr><td>PO Number</td><td>{{ $gate->po_number ?: '—' }}</td><td>Gate Number</td><td>{{ $gate->gate_no }}</td><td>Vendor</td><td>{{ $gate->vendor_name ?: '—' }}</td><td>Dock</td><td>{{ $gate->loading_dock ?: '—' }}</td></tr>
    <tr>@foreach($columns as $column)<th>{{ $columnLabels[$column] }}</th>@endforeach</tr>
    @forelse ($reportRows as $row)
        <tr>@foreach($columns as $column)<td>{{ in_array($column, ['unit_price'], true) && $row[$column] !== null ? number_format((float) $row[$column], 4) : (in_array($column, ['invoice_amount', 'accepted_value'], true) && $row[$column] !== null ? number_format((float) $row[$column], 2) : ($row[$column] ?? '')) }}</td>@endforeach</tr>
    @empty<tr><td colspan="{{ count($columns) }}">No SKU lines are available.</td></tr>@endforelse
    <tr><td colspan="{{ count($columns) }}">GRN posting is blocked until any QC hold is resolved; this export records the final on-hold quantity at closure.</td></tr>
</table>
