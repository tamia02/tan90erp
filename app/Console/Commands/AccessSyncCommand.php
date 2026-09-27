<?php

namespace App\Console\Commands;

use App\Models\Access\AccessPermission;
use App\Models\Access\DashboardWidget;
use App\Models\Access\DashboardWidgetCatalog;
use App\Services\Access\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class AccessSyncCommand extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Synchronise the additive access-control permission and widget catalogue.';

    public function handle(PermissionRegistry $registry): int
    {
        foreach ($registry->permissions() as $permission) {
            AccessPermission::updateOrCreate(['key' => $permission['key']], $permission);
        }

        foreach ($registry->widgets() as $widget) {
            DashboardWidget::updateOrCreate(['key' => $widget['key']], $widget + ['default_w' => 4, 'default_h' => 3, 'enabled' => true]);
            DashboardWidgetCatalog::updateOrCreate(['key' => $widget['key']], $widget + [
                'supported_variants_json' => ['KPI', 'list', 'chart', 'progress', 'queue'],
                'settings_schema_json' => [],
                'default_w' => 4,
                'default_h' => 3,
                'status' => 'active',
            ]);
        }

        $registered = AccessPermission::whereNotNull('route_name')->pluck('route_name')->all();
        $missing = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn ($name) => ! str_starts_with($name, 'livewire.') && ! str_starts_with($name, 'storage.') && ! str_starts_with($name, 'password.') && ! str_starts_with($name, 'verification.') && ! in_array($name, ['login', 'logout', 'profile', 'default.livewire.update'], true) && ! in_array($name, $registered, true))
            ->values();

        foreach ($missing as $routeName) {
            AccessPermission::updateOrCreate(
                ['key' => str_replace(['-', '.index'], ['_', '.view'], $routeName).'.route.view'],
                [
                    'module' => $this->moduleForRoute($routeName),
                    'screen' => $routeName,
                    'category' => 'page',
                    'action' => 'view',
                    'label' => 'View '.$routeName,
                    'description' => 'Auto-registered by access:sync for route coverage.',
                    'route_name' => $routeName,
                    'allowed_scope_types' => ['self', 'assigned', 'team', 'unit', 'vertical', 'all'],
                    'sort_order' => 900,
                    'is_sensitive' => false,
                    'status' => 'active',
                ]
            );
        }

        $this->info('Access permissions synced: '.count($registry->permissions()));
        $this->info('Dashboard widgets synced: '.count($registry->widgets()));
        $this->info('Auto-registered route permissions: '.$missing->count());

        return self::SUCCESS;
    }

    private function moduleForRoute(string $routeName): string
    {
        return match (true) {
            str_starts_with($routeName, 'tan90.master-data') => 'Master Data',
            str_starts_with($routeName, 'tan90.brc') => 'BOM Recipe Costing',
            str_starts_with($routeName, 'grn') => 'GRN',
            str_starts_with($routeName, 'qc') => 'QC',
            str_starts_with($routeName, 'vendor') => 'Vendor',
            str_starts_with($routeName, 'finance') => 'Finance',
            str_starts_with($routeName, 'admin') => 'Admin',
            str_starts_with($routeName, 'access') => 'Access Control',
            str_starts_with($routeName, 'workspace') => 'Workspace',
            default => 'Shared',
        };
    }
}
