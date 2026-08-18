<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zoho' => [
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'refresh_token' => env('ZOHO_REFRESH_TOKEN'),
        'accounts_base_url' => env('ZOHO_ACCOUNTS_BASE_URL', 'https://accounts.zoho.in'),
        'po_module_api_name' => env('ZOHO_PO_MODULE_API_NAME', 'Purchase_Orders'),
        'field_po' => env('ZOHO_FIELD_PO', 'PO_Number'),
        'field_vendor_name' => env('ZOHO_FIELD_VENDOR_NAME', 'Vendor_Name'),
        'field_subject' => env('ZOHO_FIELD_SUBJECT', 'Subject'),
        'field_product_details' => env('ZOHO_FIELD_PRODUCT_DETAILS', 'Purchase_Items'),
        'webhook_secret' => env('ZOHO_WEBHOOK_SECRET'),
        'sync_minutes' => env('ZOHO_SYNC_MINUTES', 30),
        'write_enabled' => env('ZOHO_WRITE_ENABLED', true),
        'create_enabled' => env('ZOHO_CREATE_ENABLED', false),
        'write_vendor_name' => env('ZOHO_WRITE_VENDOR_NAME', false),
        'write_product_details' => env('ZOHO_WRITE_PRODUCT_DETAILS', false),

        // Zoho Inventory — the operational sync target (Items, Vendors,
        // Purchase Orders, Bills, Purchase Receives). Separate refresh token
        // because it needs Inventory-scoped access; same OAuth client as CRM
        // above unless the client registers a dedicated one. Inactive (and
        // every ZohoInventoryService call a safe no-op) until both
        // organization_id and refresh_token are filled in.
        'inventory' => [
            'organization_id' => env('ZOHO_INVENTORY_ORGANIZATION_ID'),
            'refresh_token' => env('ZOHO_INVENTORY_REFRESH_TOKEN'),
            'client_id' => env('ZOHO_INVENTORY_CLIENT_ID', env('ZOHO_CLIENT_ID')),
            'client_secret' => env('ZOHO_INVENTORY_CLIENT_SECRET', env('ZOHO_CLIENT_SECRET')),
            'api_base_url' => env('ZOHO_INVENTORY_API_BASE_URL', 'https://www.zohoapis.in/inventory/v1'),
            'write_enabled' => env('ZOHO_INVENTORY_WRITE_ENABLED', true),
            // How long push/sync pause entirely after hitting Zoho's daily API call
            // cap (error code 45), so the 30-minute cron can't immediately re-exhaust
            // quota the moment it frees up. See ZohoInventoryService::inRateLimitCooldown().
            'rate_limit_cooldown_minutes' => env('ZOHO_INVENTORY_RATE_LIMIT_COOLDOWN_MINUTES', 180),
        ],
    ],

    'claude' => [
        'client_id' => env('CLAUDE_CLIENT_ID'),
        'client_secret' => env('CLAUDE_CLIENT_SECRET'),
        'redirect_uri' => env('CLAUDE_REDIRECT_URI', 'http://localhost:8000/oauth/claude/callback'),
        'api_key' => env('CLAUDE_API_KEY'),
        'api_url' => env('CLAUDE_API_URL', 'https://api.anthropic.com/v1'),
    ],

];
