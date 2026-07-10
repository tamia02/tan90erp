<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
        // Catches every login/logout regardless of which form triggers it —
        // replaces the React prototype's LOGIN/LOGOUT audit log entries.
        Event::listen(function (Login $event) {
            /** @var User $user */
            $user = $event->user;
            AuditLogger::log("{$user->role->label()} signed in", $user->name);
        });

        Event::listen(function (Logout $event) {
            /** @var User|null $user */
            $user = $event->user;
            if ($user) {
                AuditLogger::log("{$user->role->label()} signed out", $user->name);
            }
        });
    }
}
