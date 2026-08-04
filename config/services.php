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
    ],

    'claude' => [
        'client_id' => env('CLAUDE_CLIENT_ID'),
        'client_secret' => env('CLAUDE_CLIENT_SECRET'),
        'redirect_uri' => env('CLAUDE_REDIRECT_URI', 'http://localhost:8000/oauth/claude/callback'),
        'api_key' => env('CLAUDE_API_KEY'),
        'api_url' => env('CLAUDE_API_URL', 'https://api.anthropic.com/v1'),
    ],

];
