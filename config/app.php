<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    // One-click demo logins (role / demo-user / Tan90 role) sign in WITHOUT a
    // password. Explicitly enable for staging only; middleware also blocks
    // these shortcuts outside local/staging/testing environments.
    'demo_logins' => (bool) env('DEMO_LOGINS', false),

    // Password-less staging access is limited to synthetic production and
    // fulfilment accounts. Never include copied ERP users or Super Admin.
    'demo_login_emails' => [
        'head.manufacturing@tan90.demo',
        'manager.production@tan90.demo',
        'manager.quality.mfg@tan90.demo',
        'manager.maintenance@tan90.demo',
        'planner@tan90.demo',
        'supervisor@tan90.demo',
        'operator@tan90.demo',
        'qc.executive.mfg@tan90.demo',
        'technician@tan90.demo',
        'head.fulfilment@tan90.demo',
        'manager.warehouse@tan90.demo',
        'manager.logistics@tan90.demo',
        'manager.customer@tan90.demo',
        'fgstore@tan90.demo',
        'pickpack@tan90.demo',
        'dispatch@tan90.demo',
        'transport@tan90.demo',
        'closure@tan90.demo',
        // Existing synthetic Stage accounts from the shared Access Control,
        // GRN, Quality, Master Data, Procurement, and BRC seed sets.
        'executive.finance@tan90.demo',
        'executive.grn@tan90.demo',
        'executive.grnqc@tan90.demo',
        'executive.masterdata@tan90.demo',
        'executive.qc@tan90.demo',
        'executive.readonly@tan90.demo',
        'executive.vendor@tan90.demo',
        'head.finance@tan90.demo',
        'head.masterdata@tan90.demo',
        'head.procurement@tan90.demo',
        'head.quality@tan90.demo',
        'head.store@tan90.demo',
        'manager.finance@tan90.demo',
        'manager.grn@tan90.demo',
        'manager.masterdata@tan90.demo',
        'manager.qc@tan90.demo',
        'manager.vendor@tan90.demo',
        'stage-demo-finance@tan90.demo',
        'stage-demo-guard@tan90.demo',
        'stage-demo-qc@tan90.demo',
        'stage-demo-role-audit@tan90.demo',
        'stage-demo-role-costing@tan90.demo',
        'stage-demo-role-finance@tan90.demo',
        'stage-demo-role-formulation@tan90.demo',
        'stage-demo-role-mdm@tan90.demo',
        'stage-demo-role-plant@tan90.demo',
        'stage-demo-role-procurement@tan90.demo',
        'stage-demo-role-production-eng@tan90.demo',
        'stage-demo-role-qa-approver@tan90.demo',
        'stage-demo-role-qc@tan90.demo',
        'stage-demo-role-rnd@tan90.demo',
        'stage-demo-storeexec@tan90.demo',
        'stage-demo-storemanager@tan90.demo',
        'stage-demo-vendor@tan90.demo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'Asia/Kolkata',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
