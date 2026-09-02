<?php

use App\Enums\Role;
use App\Http\Controllers\AccessControl\AccessPeopleController;
use App\Http\Controllers\AccessControl\AccessRoleController;
use App\Http\Controllers\AccessControl\AccessSimulatorController;
use App\Http\Controllers\AccessControl\ActivityController;
use App\Http\Controllers\AccessControl\DashboardBuilderController;
use App\Http\Controllers\AccessControl\HierarchyController;
use App\Http\Controllers\AccessControl\SavedViewController;
use App\Http\Controllers\ClaudeOAuthController;
use App\Http\Controllers\Forge\BatchController;
use App\Http\Controllers\Forge\DeviationController;
use App\Http\Controllers\Forge\FinalQcController;
use App\Http\Controllers\Forge\ForgeDashboardController;
use App\Http\Controllers\Forge\FreezerController;
use App\Http\Controllers\Forge\MachineController;
use App\Http\Controllers\Forge\YieldAnalysisController;
use App\Http\Controllers\Forge\ProductionPlanController;
use App\Http\Controllers\Forge\QualityHoldController;
use App\Http\Controllers\Forge\WastageController;
use App\Http\Controllers\Forge\WorkOrderController;
use App\Http\Controllers\Flow\CustomerOrderController;
use App\Http\Controllers\Flow\DeliveryController;
use App\Http\Controllers\Flow\DispatchController;
use App\Http\Controllers\Flow\FlowDashboardController;
use App\Http\Controllers\Flow\InventoryController;
use App\Http\Controllers\Flow\PackingController;
use App\Http\Controllers\Flow\ReturnController;
use App\Http\Controllers\Flow\WaveController;
use App\Http\Controllers\Workspace\ApprovalController;
use App\Http\Controllers\Workspace\ExceptionController;
use App\Http\Controllers\Workspace\TaskController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\ZohoWebhookController;
use App\Livewire\Actions\Logout;
use App\Models\QcResult;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');
Route::redirect('public', '/login');

Route::post('zoho/webhook/purchase-order', [ZohoWebhookController::class, 'purchaseOrder'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('zoho.webhook.purchase-order');

Route::post('logout', function (Logout $logout) {
    $logout();

    return redirect('/login');
})->middleware('auth')->name('logout');

// Demo login shortcuts — every route below logs a browser in as an
// arbitrary seeded account with zero credentials. Deliberately reachable
// even in production: this deployment (tan.bookmytimes.in) only ever
// holds seeded demo/reference data, never real client data, and the
// client needs one-click access to every role for weekly walkthroughs.
// Explicit, informed decision — do not re-add an isProduction() gate
// here without checking with the project owner first.
Route::get('role-login/{role}', function (string $role) {
    $role = Role::from($role);
    $user = User::where('role', $role)->firstOrFail();

    Auth::login($user);
    Session::regenerate();

    return redirect(rtrim(config('app.url'), '/').route($role->homeRouteName(), [], false));
})->whereIn('role', Role::values())->name('role-login');

Route::get('tan90-role-login/{roleCode}', function (string $roleCode) {
    $role = App\Models\Tan90\MasterData\Role::where('code', $roleCode)->firstOrFail();
    $profile = UserProfile::where('tan90_role_id', $role->id)->firstOrFail();

    Auth::login($profile->user);
    Session::regenerate();

    $bomOnlyCodes = ['ROLE-RND', 'ROLE-FORMULATION', 'ROLE-COSTING', 'ROLE-PRODUCTION-ENG', 'ROLE-QA-APPROVER'];
    $homeRoute = in_array($roleCode, $bomOnlyCodes, true) ? 'tan90.brc.dashboard' : 'tan90.master-data.dashboard';

    return redirect(rtrim(config('app.url'), '/').route($homeRoute, [], false));
})->name('tan90-role-login');

Route::get('demo-user-login/{user}', function (User $user) {
    abort_unless($user->is_active, 404);

    Auth::login($user);
    Session::regenerate();

    if ($user->role instanceof Role) {
        return redirect(rtrim(config('app.url'), '/').route($user->role->homeRouteName(), [], false));
    }

    $profile = $user->tan90Profile()->with('role')->first();
    if ($profile?->role) {
        $bomOnlyCodes = ['ROLE-RND', 'ROLE-FORMULATION', 'ROLE-COSTING', 'ROLE-PRODUCTION-ENG', 'ROLE-QA-APPROVER'];
        $homeRoute = in_array($profile->role->code, $bomOnlyCodes, true) ? 'tan90.brc.dashboard' : 'tan90.master-data.dashboard';

        return redirect(rtrim(config('app.url'), '/').route($homeRoute, [], false));
    }

    if (app(App\Services\Access\AccessControlService::class)->can($user, 'workspace.view')) {
        return redirect()->route('workspace.index');
    }

    return redirect('/login');
})->name('demo-user-login');

Route::post('demo-login/{user}', function (User $user) {
    abort_unless(str_ends_with($user->email, '@tan90.demo') && $user->access_mode === 'advanced', 403);

    Auth::login($user);
    Session::regenerate();

    return redirect()->route('workspace.index');
})->name('demo-login');

Route::get('oauth/claude/initiate', [ClaudeOAuthController::class, 'initiate'])->name('claude.initiate');
Route::get('oauth/claude/callback', [ClaudeOAuthController::class, 'callback'])->name('claude.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    Route::post('api/claude/chat', 'App\Http\Controllers\ClaudeOAuthController@chat')->name('claude.chat');

    Volt::route('notifications', 'shared.notifications')->name('notifications');
    Volt::route('activity', 'shared.activity-log')->name('activity');
    Volt::route('activity/{entry}', 'shared.activity-detail')->name('activity.detail');
    Volt::route('settings', 'shared.settings')->name('settings');
    Volt::route('help', 'shared.help-support')->name('help');

    Route::middleware('role:guard')->prefix('guard')->name('guard.')->group(function () {
        Volt::route('/', 'guard.dashboard')->name('dashboard');
        Volt::route('scan', 'guard.bill-scan')->name('scan');
        Volt::route('entries', 'guard.entries')->name('entries');
        Volt::route('entries/{entry}', 'guard.entry-detail')->name('entries.show');
    });

    Route::middleware('role:vendor')->prefix('vendor')->name('vendor.')->group(function () {
        Volt::route('/', 'vendor.dashboard')->name('dashboard');
        Volt::route('submissions', 'vendor.submissions')->name('submissions');
        Volt::route('stock', 'vendor.stock-update')->name('stock');
        Volt::route('submissions/{submission}/activity', 'vendor.submission-activity')->name('submission-activity');
    });

    Route::middleware('role:storeExec')->prefix('unloading')->name('unloading.')->group(function () {
        Volt::route('/', 'unloading.dashboard')->name('dashboard');
        Volt::route('loading-desk', 'unloading.loading-desk')->name('loading-desk');
        Volt::route('desk', 'unloading.desk')->name('desk');
        Volt::route('history', 'unloading.history')->name('history');
        Volt::route('dock-scheduling', 'unloading.dock-scheduling')->name('dock-scheduling');
    });

    Route::middleware('role:qc')->prefix('qc')->name('qc.')->group(function () {
        Volt::route('/', 'qc.dashboard')->name('dashboard');
        Volt::route('queue', 'qc.queue')->name('queue');
        Volt::route('history', 'qc.history')->name('history');
        Volt::route('holds', 'qc.quality-holds')->name('holds');
        Route::get('holds/{qcResult}/document', function (QcResult $qcResult) {
            abort_unless($qcResult->hold_document_path, 404);

            return Storage::disk('local')->download($qcResult->hold_document_path);
        })->name('hold-document');
    });

    Route::middleware('role:storeManager')->prefix('grn')->name('grn.')->group(function () {
        Volt::route('/', 'grn.dashboard')->name('dashboard');
        Volt::route('check', 'grn.check')->name('check');
        Volt::route('register', 'grn.register')->name('register');
        Volt::route('stock-balance', 'grn.stock-balance')->name('stock-balance');
        Volt::route('ledger', 'grn.stock-ledger')->name('ledger');
        Volt::route('bins', 'grn.shelf-bin')->name('bins');
    });

    Route::middleware('role:storeManager')->group(function () {
        Volt::route('validation', 'validation-issues')->name('validation.issues');
    });

    Route::middleware('role:finance')->prefix('finance')->name('finance.')->group(function () {
        Volt::route('/', 'finance.dashboard')->name('dashboard');
        Volt::route('review', 'finance.review')->name('review');
        Volt::route('claims', 'finance.vendor-claims')->name('claims');
        Volt::route('reports', 'finance.reports')->name('reports');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('/', 'admin.overview')->name('dashboard');
        Volt::route('users', 'admin.users')->name('users');
        Volt::route('sku', 'admin.sku-master')->name('sku');
        Volt::route('vendors', 'admin.vendor-master')->name('vendors');
        Volt::route('po', 'admin.po-master')->name('po');
        Volt::route('rfq', 'admin.rfq')->name('rfq');
        Volt::route('quote-comparison', 'admin.quote-comparison')->name('quote-comparison');
        Volt::route('vendor-scorecard', 'admin.vendor-scorecard')->name('vendor-scorecard');
        Volt::route('integrations', 'admin.integrations')->name('integrations');
        Volt::route('reports', 'admin.reports')->name('reports');
    });

    Route::middleware('role:admin')->group(function () {
        Volt::route('command-center', 'admin.command-center')->name('command-center');
    });

    Route::prefix('access-control')->name('access.')->group(function () {
        Route::get('roles', [AccessRoleController::class, 'index'])->name('roles.index');
        Route::get('roles/create', [AccessRoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [AccessRoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [AccessRoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [AccessRoleController::class, 'update'])->name('roles.update');
        Route::post('roles/{role}/clone', [AccessRoleController::class, 'clone'])->name('roles.clone');

        Route::get('people', [AccessPeopleController::class, 'index'])->name('people.index');
        Route::get('people/create', [AccessPeopleController::class, 'create'])->name('people.create');
        Route::post('people', [AccessPeopleController::class, 'store'])->name('people.store');
        Route::get('people/{user}', [AccessPeopleController::class, 'show'])->name('people.show');
        Route::post('people/{user}/assign-role', [AccessPeopleController::class, 'assign'])->name('people.assign-role');
        Route::post('people/{user}/extra-access', [AccessPeopleController::class, 'grantOverride'])->name('people.extra-access');

        Route::get('hierarchy', [HierarchyController::class, 'index'])->name('hierarchy.index');
        Route::post('hierarchy/verticals', [HierarchyController::class, 'storeVertical'])->name('hierarchy.verticals.store');
        Route::post('hierarchy/units', [HierarchyController::class, 'storeUnit'])->name('hierarchy.units.store');
        Route::post('hierarchy/teams', [HierarchyController::class, 'storeTeam'])->name('hierarchy.teams.store');
        Route::post('hierarchy/shifts', [HierarchyController::class, 'storeShift'])->name('hierarchy.shifts.store');
        Route::post('hierarchy/positions', [HierarchyController::class, 'savePosition'])->name('hierarchy.positions.save');
        Route::get('teams', [HierarchyController::class, 'index'])->name('teams.index');
        Route::get('views', [SavedViewController::class, 'index'])->name('views.index');
        Route::post('views', [SavedViewController::class, 'store'])->name('views.store');
        Route::post('views/{view}/assign', [SavedViewController::class, 'assign'])->name('views.assign');
        Route::post('views/{view}/publish', [SavedViewController::class, 'publish'])->name('views.publish');
        Route::get('dashboard-builder', [DashboardBuilderController::class, 'index'])->name('dashboard-builder.index');
        Route::post('dashboard-builder', [DashboardBuilderController::class, 'save'])->name('dashboard-builder.save');
        Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
        Route::get('activity/export', [ActivityController::class, 'export'])->name('activity.export');
        Route::get('activity/{log}', [ActivityController::class, 'show'])->name('activity.show');
        Route::get('simulator', [AccessSimulatorController::class, 'index'])->name('simulator.index');
    });

    Route::get('workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::get('workspace/customise', [WorkspaceController::class, 'customise'])->name('workspace.customise');
    Route::post('workspace/customise', [WorkspaceController::class, 'save'])->name('workspace.save');

    Route::prefix('workspace/tasks')->name('workspace.tasks.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::post('/', [TaskController::class, 'store'])->name('store');
        Route::post('{task}/claim', [TaskController::class, 'claim'])->name('claim');
        Route::post('{task}/complete', [TaskController::class, 'complete'])->name('complete');
    });

    Route::prefix('workspace/approvals')->name('workspace.approvals.')->group(function () {
        Route::get('/', [ApprovalController::class, 'index'])->name('index');
        Route::post('/', [ApprovalController::class, 'store'])->name('store');
        Route::post('{approval}/decide', [ApprovalController::class, 'decide'])->name('decide');
    });

    Route::prefix('workspace/exceptions')->name('workspace.exceptions.')->group(function () {
        Route::get('/', [ExceptionController::class, 'index'])->name('index');
        Route::post('/', [ExceptionController::class, 'store'])->name('store');
        Route::post('{exception}/acknowledge', [ExceptionController::class, 'acknowledge'])->name('acknowledge');
        Route::post('{exception}/resolve', [ExceptionController::class, 'resolve'])->name('resolve');
    });

    Route::prefix('forge')->name('forge.')->group(function () {
        Route::get('/', [ForgeDashboardController::class, 'index'])->name('dashboard');

        Route::prefix('plans')->name('plans.')->group(function () {
            Route::get('/', [ProductionPlanController::class, 'index'])->name('index');
            Route::post('/', [ProductionPlanController::class, 'store'])->name('store');
            Route::post('{plan}/approve', [ProductionPlanController::class, 'approve'])->name('approve');
        });

        Route::prefix('workorders')->name('workorders.')->group(function () {
            Route::get('/', [WorkOrderController::class, 'index'])->name('index');
            Route::post('/', [WorkOrderController::class, 'store'])->name('store');
            Route::get('{workOrder}', [WorkOrderController::class, 'show'])->name('show');
            Route::post('{workOrder}/release', [WorkOrderController::class, 'release'])->name('release');
            Route::post('{workOrder}/reserve-material', [WorkOrderController::class, 'reserveMaterial'])->name('reserve-material');
            Route::post('{workOrder}/issue-material', [WorkOrderController::class, 'issueMaterial'])->name('issue-material');
            Route::post('{workOrder}/start', [WorkOrderController::class, 'start'])->name('start');
            Route::post('{workOrder}/record-production', [WorkOrderController::class, 'recordProduction'])->name('record-production');
            Route::post('{workOrder}/send-to-final-qc', [WorkOrderController::class, 'sendToFinalQc'])->name('send-to-final-qc');
            Route::post('{workOrder}/close', [WorkOrderController::class, 'close'])->name('close');
        });

        Route::prefix('job-cards')->name('job-cards.')->group(function () {
            Route::post('{jobCard}/start', [WorkOrderController::class, 'startJobCard'])->name('start');
            Route::post('{jobCard}/pause', [WorkOrderController::class, 'pauseJobCard'])->name('pause');
            Route::post('{jobCard}/resume', [WorkOrderController::class, 'resumeJobCard'])->name('resume');
            Route::post('{jobCard}/complete', [WorkOrderController::class, 'completeJobCard'])->name('complete');
        });

        Route::post('production-entries/{entry}/approve', [WorkOrderController::class, 'approveProduction'])->name('production.approve');

        Route::prefix('machines')->name('machines.')->group(function () {
            Route::get('/', [MachineController::class, 'index'])->name('index');
            Route::post('{machine}/downtime', [MachineController::class, 'startDowntime'])->name('downtime');
            Route::post('{machine}/state', [MachineController::class, 'setState'])->name('state');
            Route::post('downtime/{downtime}/close', [MachineController::class, 'closeDowntime'])->name('downtime.close');
        });

        Route::prefix('freezers')->name('freezers.')->group(function () {
            Route::get('/', [FreezerController::class, 'index'])->name('index');
            Route::post('{freezer}/readings', [FreezerController::class, 'recordReading'])->name('readings.store');
            Route::post('{freezer}/assign', [FreezerController::class, 'assignBatch'])->name('assign');
            Route::post('logs/{log}/release', [FreezerController::class, 'releaseBatch'])->name('release');
        });

        Route::get('yield', [YieldAnalysisController::class, 'index'])->name('yield.index');

        Route::prefix('wastage')->name('wastage.')->group(function () {
            Route::get('/', [WastageController::class, 'index'])->name('index');
            Route::post('/', [WastageController::class, 'store'])->name('store');
            Route::post('{wastage}/approve', [WastageController::class, 'approve'])->name('approve');
        });

        Route::prefix('quality-holds')->name('quality-holds.')->group(function () {
            Route::get('/', [QualityHoldController::class, 'index'])->name('index');
            Route::post('/', [QualityHoldController::class, 'store'])->name('store');
            Route::post('{qualityHold}/release', [QualityHoldController::class, 'release'])->name('release');
        });

        Route::prefix('final-qc')->name('final-qc.')->group(function () {
            Route::get('/', [FinalQcController::class, 'index'])->name('index');
            Route::post('{workOrder}', [FinalQcController::class, 'store'])->name('store');
            Route::post('{finalQcResult}/release', [FinalQcController::class, 'release'])->name('release');
        });

        Route::prefix('deviations')->name('deviations.')->group(function () {
            Route::get('/', [DeviationController::class, 'index'])->name('index');
            Route::post('/', [DeviationController::class, 'store'])->name('store');
            Route::put('{deviation}', [DeviationController::class, 'update'])->name('update');
            Route::post('{deviation}/rework-order', [DeviationController::class, 'createReworkOrder'])->name('rework-order');
        });

        Route::prefix('batches')->name('batches.')->group(function () {
            Route::get('/', [BatchController::class, 'index'])->name('index');
            Route::get('{batch}', [BatchController::class, 'show'])->name('show');
        });
    });

    Route::prefix('flow')->name('flow.')->group(function () {
        Route::get('/', [FlowDashboardController::class, 'index'])->name('dashboard');

        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::post('receive/{batch}', [InventoryController::class, 'receive'])->name('receive');
            Route::post('{lot}/putaway', [InventoryController::class, 'putaway'])->name('putaway');
        });

        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
            Route::post('/', [CustomerOrderController::class, 'store'])->name('store');
            Route::get('{order}', [CustomerOrderController::class, 'show'])->name('show');
            Route::post('{order}/lines', [CustomerOrderController::class, 'addLine'])->name('lines.store');
            Route::post('{order}/validate', [CustomerOrderController::class, 'validateOrder'])->name('validate');
            Route::post('{order}/release', [CustomerOrderController::class, 'release'])->name('release');
        });

        Route::prefix('waves')->name('waves.')->group(function () {
            Route::get('/', [WaveController::class, 'index'])->name('index');
            Route::post('/', [WaveController::class, 'store'])->name('store');
            Route::post('{wave}/publish', [WaveController::class, 'publish'])->name('publish');
            Route::post('pick-tasks/{pickTask}/confirm', [WaveController::class, 'confirmPick'])->name('pick-tasks.confirm');
        });

        Route::prefix('packing')->name('packing.')->group(function () {
            Route::get('/', [PackingController::class, 'index'])->name('index');
            Route::post('{order}', [PackingController::class, 'store'])->name('store');
            Route::post('handling-units/{handlingUnit}/seal', [PackingController::class, 'seal'])->name('seal');
        });

        Route::prefix('dispatch')->name('dispatch.')->group(function () {
            Route::get('/', [DispatchController::class, 'index'])->name('index');
            Route::post('/', [DispatchController::class, 'store'])->name('store');
            Route::post('{shipment}/load/{handlingUnit}', [DispatchController::class, 'loadUnit'])->name('load');
            Route::post('{shipment}/release', [DispatchController::class, 'release'])->name('release');
            Route::post('{shipment}/temperature', [DispatchController::class, 'recordTemperature'])->name('temperature');
            Route::post('temperature/{temperatureEvent}/disposition', [DispatchController::class, 'dispositionExcursion'])->name('temperature.disposition');
        });

        Route::prefix('deliveries')->name('deliveries.')->group(function () {
            Route::get('/', [DeliveryController::class, 'index'])->name('index');
            Route::post('{shipment}', [DeliveryController::class, 'store'])->name('store');
            Route::post('{delivery}/close', [DeliveryController::class, 'close'])->name('close');
        });

        Route::prefix('returns')->name('returns.')->group(function () {
            Route::get('/', [ReturnController::class, 'index'])->name('index');
            Route::post('/', [ReturnController::class, 'store'])->name('store');
            Route::post('{return}/inspect', [ReturnController::class, 'inspect'])->name('inspect');
        });
    });
});

require __DIR__.'/auth.php';
