<?php

// Outward shipment GST rules (Indian GST). Values follow the client's flowchart.
return [
    // Annual turnover above Rs 5 crore -> B2B tax invoices need an e-Invoice IRN.
    'einvoice_applicable' => env('OUTWARD_EINVOICE_APPLICABLE', true),

    // E-Way Bill needed above this consignment value (Rs) where the flowchart
    // says "check threshold" (B2C sales, same-GSTIN delivery challans).
    'ewb_threshold' => (float) env('OUTWARD_EWB_THRESHOLD', 50000),

    // Upload limits for invoice / IRN / E-Way Bill files (KB) - same as the POD upload.
    'max_upload_kb' => 10240,
];
