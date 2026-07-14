<?php

namespace App\Providers\Tan90;

use App\Console\Commands\Tan90\MasterData\CheckSlaBreaches;
use App\Policies\Tan90\MasterData\Tan90MasterDataPolicy;
use App\Services\Tan90\MasterData\EntityRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Tan90 Master Data module into the host Laravel app. Must be
 * registered once in bootstrap/providers.php - see docs/INSTALL.md. Kept
 * deliberately separate from the app's own AuthServiceProvider/AppServiceProvider
 * so this module never has to edit files outside its own path list.
 */
class MasterDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/tan90_master_data.php', 'tan90_master_data');
        $this->commands([CheckSlaBreaches::class]);
    }

    public function boot(EntityRegistry $registry): void
    {
        $this->loadRoutesFrom(base_path('routes/tan90_master_data.php'));
        $this->loadViewsFrom(__DIR__ . '/../../../resources/views/tan90/master-data', 'tan90-master-data');
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        foreach ($registry->all() as $config) {
            Gate::policy($config['model'], Tan90MasterDataPolicy::class);
        }

        $this->publishes([
            __DIR__ . '/../../../public/tan90-master-data' => public_path('tan90-master-data'),
        ], 'tan90-master-data-assets');

        // Registered here (rather than routes/console.php) so this module never has
        // to edit a file outside its own path list. Requires the host app's own
        // scheduler to actually be running: `php artisan schedule:work` in dev, or
        // a single `* * * * * php artisan schedule:run` cron entry in production.
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command(CheckSlaBreaches::class)->hourly();
        });
    }
}
