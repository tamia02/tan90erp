<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Delivery Challan {{ $shipment->challan_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 13px; color: #111; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; text-align: center; }
        .sub { text-align: center; color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        .num { text-align: right; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .box { border: 1px solid #999; padding: 8px; }
        .sign { margin-top: 48px; display: flex; justify-content: space-between; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">Print</button></p>
    <h1>DELIVERY CHALLAN</h1>
    <div class="sub">{{ $shipment->purposeLabel() }} · Not a sale - goods sent without a Tax Invoice</div>

    <div class="grid">
        <div class="box">
            <b>Challan no.:</b> {{ $shipment->challan_number }}<br>
            <b>Date:</b> {{ $shipment->created_at->format('d M Y') }}<br>
            <b>Our ref.:</b> {{ $shipment->number }}{{ $shipment->sales_order_number ? ' · '.$shipment->sales_order_number : '' }}<br>
            @if ($shipment->ewb_number)<b>E-Way Bill:</b> {{ $shipment->ewb_number }}{{ $shipment->ewb_valid_until ? ' (valid until '.$shipment->ewb_valid_until->format('d M Y').')' : '' }}<br>@endif
            @if ($shipment->vehicle_number)<b>Vehicle:</b> {{ $shipment->vehicle_number }}<br>@endif
            @if ($shipment->transporter)<b>Transporter:</b> {{ $shipment->transporter }}@endif
        </div>
        <div class="box">
            <b>From:</b> {{ $shipment->from_name }}<br>
            GSTIN {{ $shipment->from_gstin ?: '-' }} · {{ $shipment->from_state }}<br><br>
            <b>To:</b> {{ $shipment->to_name }}<br>
            GSTIN {{ $shipment->to_gstin ?: '-' }} · {{ $shipment->to_state }}<br>
            {{ $shipment->to_address }}
        </div>
    </div>

    <table>
        <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Rate (Rs)</th><th class="num">Value (Rs)</th></tr></thead>
        <tbody>
            @foreach ($shipment->lines as $line)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $line->item?->displayName() }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $line->qty, '0'), '.') }}</td>
                    <td>{{ $line->item?->unit }}</td>
                    <td class="num">{{ number_format((float) $line->rate, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->value, 2) }}</td>
                </tr>
            @endforeach
            <tr><th colspan="5" class="num">Total consignment value</th><th class="num">{{ number_format((float) $shipment->total_value, 2) }}</th></tr>
        </tbody>
    </table>
    @if ($shipment->notes)<p><b>Notes:</b> {{ $shipment->notes }}</p>@endif

    <div class="sign">
        <div>Receiver's signature</div>
        <div>For {{ $shipment->from_name }}<br><br>Authorised signatory</div>
    </div>
</body>
</html>
