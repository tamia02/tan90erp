<?php

// The company block printed on vendor-facing documents (PO PDF), as on the
// client's Zoho Books PO. Override per environment with COMPANY_* in .env.
return [
    'name' => env('COMPANY_NAME', 'Tan90 Thermal Solutions Pvt Ltd'),
    'address_lines' => array_values(array_filter(explode('|', env('COMPANY_ADDRESS',
        'No.75, POONAMALLEE BYPASS ROAD, SENNERKUPAM|PONAMALLEE TALUK, THIRUVALLUR DISTRICT|Chennai Tamil Nadu 600056|India')))),
    'gstin' => env('COMPANY_GSTIN', '33AAHCT1080F1ZN'),
    'brand_color' => env('COMPANY_DOC_COLOR', '#34a853'),
];
