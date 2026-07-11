<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sign-in/sign-out are deliberately NOT written to the audit trail —
        // every role's Recent Activity feed is meant to be business actions
        // only, not session noise.
    }
}
