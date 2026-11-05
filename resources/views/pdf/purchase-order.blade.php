<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $po->po_number }}</title>
    {{-- Same layout as the client's Zoho Books PO (logo + company, green title, vendor / deliver-to, green item header, GST split, notes, T&C, signature) plus a scan-to-open QR. --}}
    @php
        $green = config('company.brand_color', '#34a853');
        $money = fn ($n) => \App\Support\IndianNumberFormatter::format($n);
        $qty = fn ($n) => \App\Support\IndianNumberFormatter::quantity($n, 2);
        // GST per rate, like Zoho: "SGST9 (9%)", "CGST9 (9%)", "IGST18 (18%)".
        $taxRows = [];
        foreach ($lineTaxes as $t) {
            $rate = (float) ($t['rate'] ?? 0);
            if (($t['igst'] ?? 0) > 0) {
                $k = 'IGST'.rtrim(rtrim(number_format($rate, 2), '0'), '.');
                $taxRows[$k] = ['label' => $k.' ('.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%)', 'amount' => ($taxRows[$k]['amount'] ?? 0) + $t['igst']];
            } elseif (($t['cgst'] ?? 0) > 0 || ($t['sgst'] ?? 0) > 0) {
                $half = rtrim(rtrim(number_format($rate / 2, 2), '0'), '.');
                $taxRows['SGST'.$half] = ['label' => "SGST{$half} ({$half}%)", 'amount' => ($taxRows['SGST'.$half]['amount'] ?? 0) + $t['sgst']];
                $taxRows['CGST'.$half] = ['label' => "CGST{$half} ({$half}%)", 'amount' => ($taxRows['CGST'.$half]['amount'] ?? 0) + $t['cgst']];
            }
        }
        $compact = ($po->pdf_template ?? 'standard') === 'compact';
    @endphp
    <style>
        @page { margin: {{ $compact ? '22px 26px' : '34px 40px' }}; }
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $compact ? '8px' : '9px' }}; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .company { font-size: 11px; font-weight: bold; color: #222; }
        .title { font-size: 26px; color: {{ $green }}; text-align: right; }
        .number { font-size: 10px; font-weight: bold; text-align: right; color: #222; }
        .label { font-size: 10px; color: #444; }
        .strong { font-weight: bold; color: #222; }
        .meta td { padding: 4px 0; font-size: 9px; }
        .meta .k { text-align: right; color: #555; padding-right: 10px; }
        .meta .v { text-align: right; width: 110px; }
        .items th { background: {{ $green }}; color: #fff; font-weight: normal; padding: 7px 8px; text-align: left; font-size: 9px; }
        .items td { padding: 8px; border-bottom: 1px solid #ddd; }
        .items th.r { text-align: right; }
        .r { text-align: right; }
        .muted { color: #777; font-size: 7.5px; }
        .totals td { padding: 6px 8px; }
        .grand { font-weight: bold; font-size: 10px; color: #222; }
        .section { font-size: 10px; color: #333; margin: 16px 0 4px; }
        .small { font-size: 7.5px; line-height: 1.45; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width:58%;">
                <img src="{{ public_path('images/tan90-logo.png') }}" style="height:62px;" alt="Tan90"><br>
                <div class="company" style="margin-top:4px;">{{ config('company.name') }}</div>
                @foreach (config('company.address_lines', []) as $line){{ $line }}<br>@endforeach
                GSTIN {{ config('company.gstin') }}
            </td>
            <td style="width:42%;">
                <div class="title">Purchase Order</div>
                <div class="number"># {{ $po->po_number }}</div>
                <div style="text-align:right; margin-top:6px;"><img src="{{ $qrDataUri }}" style="width:70px; height:70px;"><br><span class="muted">Scan to open this PO</span></div>
            </td>
        </tr>
    </table>

    <table style="margin-top:14px;">
        <tr>
            <td style="width:55%;">
                <div class="label">Supplier / Shipping Address</div>
                <div class="strong">{{ $po->vendor_name }}</div>
                @if ($vendor?->address_building || $vendor?->address_street){{ trim($vendor?->address_building.' '.$vendor?->address_street) }}<br>@endif
                @if ($vendor?->address_city || $vendor?->address_zip){{ trim($vendor?->address_city.'  '.$vendor?->address_zip) }}<br>@endif
                @if ($vendor?->address_state){{ $vendor->address_state }}<br>@endif
                India<br>
                @if ($vendor?->gst_number && $vendor->gst_number !== 'ZOHO-N/A')GSTIN {{ $vendor->gst_number }}@endif

                <div class="label" style="margin-top:12px;">Deliver To</div>
                @if ($po->warehouse_location)Tan90 | {{ $po->warehouse_location }}<br>@endif
                @if ($po->shipping_building || $po->shipping_street){{ trim($po->shipping_building.' '.$po->shipping_street) }}<br>@endif
                {{ trim($po->shipping_city.' '.$po->shipping_state.' '.$po->shipping_zip) }}<br>
                {{ $po->shipping_country ?: 'India' }}<br>
                @if ($deliverGstin)GSTIN {{ $deliverGstin }}@endif
            </td>
            <td style="width:45%;">
                <div class="label">Billing Address</div>
                <div class="strong">{{ $po->billing_building }}</div>
                @if ($po->billing_street){{ $po->billing_street }}<br>@endif
                {{ trim($po->billing_city.' '.$po->billing_state.' '.$po->billing_zip) }}<br>
                {{ $po->billing_country ?: 'India' }}<br>
                @if (config('company.gstin'))GSTIN {{ config('company.gstin') }}@endif
                <table class="meta">
                    @if ($po->shipment_preference)<tr><td class="k">Shipment preference :</td><td class="v">{{ $po->shipment_preference }}</td></tr>@endif
                    <tr><td class="k">Date :</td><td class="v">{{ $po->po_date?->format('d-m-Y') ?? '—' }}</td></tr>
                    @if ($po->payment_terms)<tr><td class="k">Terms :</td><td class="v">{{ $po->payment_terms }}</td></tr>@endif
                    @if ($po->due_date)<tr><td class="k">Delivery Date :</td><td class="v">{{ $po->due_date->format('d-m-Y') }}</td></tr>@endif
                    @if ($po->reference_number)<tr><td class="k">Ref# :</td><td class="v">{{ $po->reference_number }}</td></tr>@endif
                    @if ($po->valid_upto)<tr><td class="k">PO Valid Upto :</td><td class="v">{{ \Illuminate\Support\Carbon::parse($po->valid_upto)->format('d-m-Y') }}</td></tr>@endif
                    @foreach ((array) ($po->header_custom_fields ?? []) as $label => $value)
                        <tr><td class="k">{{ $label }} :</td><td class="v">{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:16px;">
        <thead><tr><th style="width:5%;">#</th><th>Item &amp; Description</th><th style="width:13%;">HSN/SAC</th><th class="r" style="width:12%;">Qty</th><th class="r" style="width:11%;">Rate</th><th class="r" style="width:14%;">Amount</th></tr></thead>
        <tbody>
            @foreach ($po->lines as $index => $line)
                @php $tax = $lineTaxes[$line->id] ?? ['taxable' => (float) $line->quantity * (float) $line->list_price]; @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><span class="strong">{{ $line->documentProduct() }}</span>
                        @if ($skuCodes[mb_strtolower(trim((string) $line->product))] ?? null)<br><span class="muted">SKU : {{ $skuCodes[mb_strtolower(trim((string) $line->product))] }}</span>@endif
                        @if ($line->custom_fields)<br><span class="muted">{{ collect($line->custom_fields)->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') }}</span>@endif
                    </td>
                    <td>{{ $line->hsn_sac ?: '' }}</td>
                    <td class="r">{{ $qty($line->quantity) }}<br><span class="muted">{{ $line->uom }}</span></td>
                    <td class="r">{{ $money($line->list_price) }}</td>
                    <td class="r">{{ $money($tax['taxable']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top:4px;">
        <tr><td style="width:55%;"></td><td class="r">Sub Total</td><td class="r" style="width:110px;">{{ $money($po->subTotal()) }}</td></tr>
        @if ((float) $po->discount > 0)<tr><td></td><td class="r">Discount</td><td class="r">(-) {{ $money($po->discount) }}</td></tr>@endif
        @foreach ($taxRows as $row)<tr><td></td><td class="r">{{ $row['label'] }}</td><td class="r">{{ $money($row['amount']) }}</td></tr>@endforeach
        @if ((float) $po->adjustment != 0)<tr><td></td><td class="r">Adjustment</td><td class="r">{{ $money($po->adjustment) }}</td></tr>@endif
        <tr><td></td><td class="r grand">Total</td><td class="r grand">₹{{ $money($po->grandTotal()) }}</td></tr>
        <tr><td></td><td colspan="2" class="r muted">{{ $totalInWords }}</td></tr>
    </table>
    @if ($po->reverse_charge)<p class="small strong">Reverse charge applicable - tax to be accounted for by the recipient.</p>@endif

    @if ($po->description && ! str_starts_with(trim($po->description), 'Synced from Zoho'))
        <div class="section">Notes</div>
        <div class="small">{!! nl2br(e($po->description)) !!}</div>
    @endif
    @if ($po->terms_and_conditions)
        <div class="section">Terms &amp; Conditions</div>
        <div class="small">{!! nl2br(e($po->terms_and_conditions)) !!}</div>
    @endif

    <div style="margin-top:46px;">
        @if (is_file(public_path('images/company-stamp.png')))<img src="{{ public_path('images/company-stamp.png') }}" style="height:90px;"><br>@endif
        <div style="border-top:1px solid #999; width:160px; padding-top:4px;">Authorized Signature</div>
    </div>
</body>
</html>
