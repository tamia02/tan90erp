<?php

use App\Http\Controllers\Tan90\BomRecipeCosting\AuditTrailController;
use App\Http\Controllers\Tan90\BomRecipeCosting\BomController;
use App\Http\Controllers\Tan90\BomRecipeCosting\BomDataController;
use App\Http\Controllers\Tan90\BomRecipeCosting\CostingController;
use App\Http\Controllers\Tan90\BomRecipeCosting\DashboardController;
use App\Http\Controllers\Tan90\BomRecipeCosting\EngineeringChangeController;
use App\Http\Controllers\Tan90\BomRecipeCosting\MrpReadinessController;
use App\Http\Controllers\Tan90\BomRecipeCosting\RecipeController;
use App\Http\Controllers\Tan90\BomRecipeCosting\RoutingController;
use App\Http\Controllers\Tan90\BomRecipeCosting\WhereUsedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tan90 BOM, Recipe & Costing routes
|--------------------------------------------------------------------------
| All routes require an authenticated session. Register this file's
| provider in bootstrap/providers.php - see docs/INSTALL.md.
*/

Route::middleware(['web', 'auth'])->prefix('tan90/brc')->name('tan90.brc.')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('audit-logs', [AuditTrailController::class, 'index'])->name('audit-logs');

    Route::get('mrp-readiness', [MrpReadinessController::class, 'index'])->name('mrp-readiness.index');
    Route::get('mrp-readiness/{finishedGood}', [MrpReadinessController::class, 'show'])->name('mrp-readiness.show');

    Route::get('where-used/component/{component}', [WhereUsedController::class, 'show'])->name('where-used.component');

    // Recipes.
    Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
    Route::get('recipes/create', [RecipeController::class, 'create'])->name('recipes.create');
    Route::post('recipes', [RecipeController::class, 'store'])->name('recipes.store');
    Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe')->name('recipes.show');
    Route::post('recipes/{recipe}/revisions', [RecipeController::class, 'newRevision'])->whereNumber('recipe')->name('recipes.revisions.store');
    Route::post('recipe-versions/{version}/lines', [RecipeController::class, 'storeLine'])->whereNumber('version')->name('recipe-versions.lines.store');
    Route::delete('recipe-versions/{version}/lines/{line}', [RecipeController::class, 'destroyLine'])->whereNumber('version')->whereNumber('line')->name('recipe-versions.lines.destroy');
    Route::get('recipe-versions/{version}/validate', [RecipeController::class, 'validateFormula'])->whereNumber('version')->name('recipe-versions.validate');
    Route::post('recipe-versions/{version}/scale', [RecipeController::class, 'scale'])->whereNumber('version')->name('recipe-versions.scale');
    Route::post('recipe-versions/{version}/gates', [RecipeController::class, 'passGate'])->whereNumber('version')->name('recipe-versions.gates.pass');

    // BOMs.
    Route::get('boms', [BomController::class, 'index'])->name('boms.index');
    Route::get('boms/create', [BomController::class, 'create'])->name('boms.create');
    Route::post('boms', [BomController::class, 'store'])->name('boms.store');
    Route::get('boms/{bom}', [BomController::class, 'show'])->whereNumber('bom')->name('boms.show');
    Route::post('boms/{bom}/revisions', [BomController::class, 'newRevision'])->whereNumber('bom')->name('boms.revisions.store');
    Route::post('bom-versions/{version}/lines', [BomController::class, 'storeLine'])->whereNumber('version')->name('bom-versions.lines.store');
    Route::delete('bom-versions/{version}/lines/{line}', [BomController::class, 'destroyLine'])->whereNumber('version')->whereNumber('line')->name('bom-versions.lines.destroy');
    Route::post('bom-versions/{version}/gates', [BomController::class, 'passGate'])->whereNumber('version')->name('bom-versions.gates.pass');
    Route::post('bom-versions/{version}/yield', [BomController::class, 'storeYield'])->whereNumber('version')->name('bom-versions.yield.store');

    // Routings.
    Route::get('routings', [RoutingController::class, 'index'])->name('routings.index');
    Route::get('routings/create', [RoutingController::class, 'create'])->name('routings.create');
    Route::post('routings', [RoutingController::class, 'store'])->name('routings.store');
    Route::get('routings/{routing}', [RoutingController::class, 'show'])->whereNumber('routing')->name('routings.show');
    Route::post('routings/{routing}/operations', [RoutingController::class, 'storeOperation'])->whereNumber('routing')->name('routings.operations.store');
    Route::delete('routings/{routing}/operations/{operation}', [RoutingController::class, 'destroyOperation'])->whereNumber('routing')->whereNumber('operation')->name('routings.operations.destroy');
    Route::post('routing-operations/{operation}/process-parameters', [RoutingController::class, 'storeProcessParameter'])->whereNumber('operation')->name('routing-operations.process-parameters.store');

    // Costing.
    Route::get('costing', [CostingController::class, 'index'])->name('costing.index');
    Route::get('costing/{costSheet}', [CostingController::class, 'show'])->whereNumber('costSheet')->name('costing.show');
    Route::post('finished-goods/{finishedGood}/rollup', [CostingController::class, 'rollup'])->whereNumber('finishedGood')->name('costing.rollup');
    Route::post('costing/{costSheet}/approve-standard', [CostingController::class, 'approveStandard'])->whereNumber('costSheet')->name('costing.approve-standard');
    Route::post('costing/{costSheet}/actual', [CostingController::class, 'recordActual'])->whereNumber('costSheet')->name('costing.actual.store');
    Route::post('costing/{costSheet}/simulate', [CostingController::class, 'simulate'])->whereNumber('costSheet')->name('costing.simulate');

    // Engineering Change Orders.
    Route::get('eco', [EngineeringChangeController::class, 'index'])->name('eco.index');
    Route::get('eco/{eco}', [EngineeringChangeController::class, 'show'])->whereNumber('eco')->name('eco.show');
    Route::post('eco/{eco}/approve', [EngineeringChangeController::class, 'approve'])->whereNumber('eco')->name('eco.approve');
    Route::post('eco/{eco}/implement', [EngineeringChangeController::class, 'implement'])->whereNumber('eco')->name('eco.implement');

    // Generic entity CRUD + governance actions for the module's simple reference masters.
    Route::get('{entity}', [BomDataController::class, 'index'])->name('index');
    Route::get('{entity}/create', [BomDataController::class, 'create'])->name('create');
    Route::post('{entity}', [BomDataController::class, 'store'])->name('store');
    Route::get('{entity}/export', [BomDataController::class, 'export'])->name('export');
    Route::get('{entity}/{id}', [BomDataController::class, 'show'])->whereNumber('id')->name('show');
    Route::get('{entity}/{id}/edit', [BomDataController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('{entity}/{id}', [BomDataController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('{entity}/{id}', [BomDataController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('{entity}/{id}/restore', [BomDataController::class, 'restore'])->whereNumber('id')->name('restore');
    Route::post('{entity}/{id}/submit', [BomDataController::class, 'submit'])->whereNumber('id')->name('submit');
    Route::post('{entity}/{id}/approve', [BomDataController::class, 'approve'])->whereNumber('id')->name('approve');
    Route::post('{entity}/{id}/reject', [BomDataController::class, 'reject'])->whereNumber('id')->name('reject');
});
