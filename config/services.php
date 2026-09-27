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

            // Master kill switch. Set false to make every Zoho Inventory call a
            // no-op without removing credentials — the fastest way to stop a
            // runaway quota drain in production.
            'sync_enabled' => env('ZOHO_INVENTORY_SYNC_ENABLED', true),

            // How long push/sync pause entirely after hitting Zoho's daily API call
            // cap (error code 45), so the 30-minute cron can't immediately re-exhaust
            // quota the moment it frees up. See ZohoApiGate.
            'rate_limit_cooldown_minutes' => env('ZOHO_INVENTORY_RATE_LIMIT_COOLDOWN_MINUTES', 180),

            // --- Rate limiting / quota budget (see App\Services\Zoho\ZohoApiGate) ---
            // Every Inventory HTTP call is gated by all three of these. Confirm the
            // real numbers against the org's Zoho subscription page — Zoho reports
            // both a short-window rate and a per-day cap, and both surface as code 45.
            'rate_limit' => [
                // Per-minute ceiling, smoothed with GCRA so calls are spaced evenly
                // rather than allowed to burst and trip the short-window limit.
                'per_minute' => (int) env('ZOHO_INVENTORY_CALLS_PER_MINUTE', 60),
                // How many calls may bunch together before pacing kicks in.
                'burst' => (int) env('ZOHO_INVENTORY_CALL_BURST', 10),
                // Working daily budget. Deliberately below the real plan cap so there
                // is headroom for webhooks, manual retries and OAuth refreshes.
                'per_day' => (int) env('ZOHO_INVENTORY_CALLS_PER_DAY', 800),
                // Zoho resets the daily counter on its own clock — match it or the
                // ceiling will be wrong either side of midnight.
                'day_timezone' => env('ZOHO_INVENTORY_QUOTA_TIMEZONE', 'Asia/Kolkata'),
                // Max calls a single cron run may spend, so one run cannot drain the
                // day's budget. Roughly per_day * 0.8 / runs-per-day.
                'per_run' => (int) env('ZOHO_INVENTORY_CALLS_PER_RUN', 120),
            ],

            // Circuit breaker: escalating cooldowns (minutes) applied on each
            // successive trip. After the last one it stays at that value. A single
            // probe call is admitted when a cooldown lapses; only its success
            // reopens the floodgates.
            'breaker' => [
                'cooldown_ladder' => array_map(
                    'intval',
                    explode(',', (string) env('ZOHO_INVENTORY_BREAKER_LADDER', '30,60,180,360')),
                ),
            ],

            // How many consecutive permanent (non-retryable) failures a single record
            // may accumulate before it is quarantined and the checkpoint advances
            // past it. Prevents one bad row pinning the whole backlog forever.
            'max_record_failures' => (int) env('ZOHO_INVENTORY_MAX_RECORD_FAILURES', 3),

            // Seconds to delay a queued push so a burst of related saves (a PO plus
            // its line items) collapses into one push instead of one per save.
            'coalesce_seconds' => (int) env('ZOHO_INVENTORY_COALESCE_SECONDS', 30),

            // HTTP timeouts — without these a hung Zoho connection blocks a queue
            // worker or a web request indefinitely.
            'timeout' => (int) env('ZOHO_INVENTORY_HTTP_TIMEOUT', 15),
            'connect_timeout' => (int) env('ZOHO_INVENTORY_HTTP_CONNECT_TIMEOUT', 5),
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
