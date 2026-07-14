<?php

namespace App\Providers\Tan90;

use App\Models\Tan90\BomRecipeCosting\AlternateComponent;
use App\Models\Tan90\BomRecipeCosting\AuditLog;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\Component;
use App\Models\Tan90\BomRecipeCosting\CostRate;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\EngineeringChangeOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\QualitySpec;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\Routing;
use App\Models\Tan90\BomRecipeCosting\SubstitutionRule;
use App\Models\Tan90\BomRecipeCosting\TemperatureProfile;
use App\Models\Tan90\BomRecipeCosting\WorkCenter;
use App\Policies\Tan90\BomRecipeCosting\Tan90BomRecipeCostingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Tan90 BOM/Recipe/Costing module into the host Laravel app. Must
 * be registered once in bootstrap/providers.php - see docs/INSTALL.md. Kept
 * separate from the app's own AuthServiceProvider/AppServiceProvider so this
 * module never has to edit files outside its own path list — same pattern as
 * Master Data's MasterDataServiceProvider.
 *
 * Requires the Master Data module (or Phase 2's house RBAC migrations) to be
 * installed first: this module's policy delegates to
 * App\Services\Tan90\MasterData\PermissionService rather than a second
 * parallel role/permission system.
 */
class BomRecipeCostingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/tan90_bom_recipe_costing.php', 'tan90_bom_recipe_costing');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/tan90_bom_recipe_costing.php'));
        $this->loadViewsFrom(__DIR__ . '/../../../resources/views/tan90/brc', 'tan90-brc');
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        foreach ([
            FinishedGood::class, Component::class, Recipe::class, Bom::class, Routing::class,
            WorkCenter::class, TemperatureProfile::class, QualitySpec::class, AlternateComponent::class,
            SubstitutionRule::class, CostRate::class, CostSheet::class, EngineeringChangeOrder::class,
            AuditLog::class,
        ] as $model) {
            Gate::policy($model, Tan90BomRecipeCostingPolicy::class);
        }

        $this->publishes([
            __DIR__ . '/../../../public/tan90-brc' => public_path('tan90-brc'),
        ], 'tan90-brc-assets');
    }
}
