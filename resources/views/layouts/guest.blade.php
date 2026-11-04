<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <link rel="icon" type="image/svg+xml" sizes="any" href="{{ asset('brand/tan90-favicon.svg') }}?v=20261024a">
        <link rel="shortcut icon" type="image/svg+xml" href="{{ asset('brand/tan90-favicon.svg') }}?v=20261024a">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('brand/tan90-logo.webp') }}?v=20261024a">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="{{ asset('brand/login.css') }}?v=20261018b">
    </head>
    <body class="font-sans text-slate-950 antialiased">
        @if (\App\Support\DeploymentIdentity::isStaging())
            <div role="status" class="bg-amber-200 px-4 py-2 text-center text-sm font-bold text-amber-950">
                STAGING · Test environment
            </div>
        @endif
        {{ $slot }}
    </body>
</html>
