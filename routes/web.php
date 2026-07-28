<?php

use App\Enums\Role;
use App\Http\Controllers\AccessControl\AccessPeopleController;
use App\Http\Controllers\AccessControl\AccessRoleController;
use App\Http\Controllers\AccessControl\ActivityController;
use App\Http\Controllers\AccessControl\DashboardBuilderController;
use App\Http\Controllers\AccessControl\HierarchyController;
use App\Http\Controllers\AccessControl\SavedViewController;
use App\Http\Controllers\ClaudeOAuthController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Livewire\Actions\Logout;
use App\Models\QcResult;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');
Route::redirect('public', '/login');

// The GRN sidebar (layout/navigation.blade.php) calls the Logout action
// directly as a Volt component method - there was never a named 'logout'
// route. The Tan90 modules' layouts (copied from standalone demos built
// against a standard Breeze-style app) use a plain <form action="{{ route
// ('logout') }}"> instead, so this route exists purely for them.
Route::post('logout', function (Logout $logout) {
    $logout();

    return redirect('/login');
})->middleware('auth')->name('logout');

// Dev/demo convenience only — logs in as any role with no password, so it
// must never be reachable in production. Gated at registration, not just
// hidden in the UI, so the URL itself 404s outside local/dev.
if (! app()->isProduction()) {
    Route::get('role-login/{role}', function (string $role) {
        $role = Role::from($role);
        $user = User::where('role', $role)->firstOrFail();

        Auth::login($user);
        Session::regenerate();

        return redirect(rtrim(config('app.url'), '/').route($role->homeRouteName(), [], false));
    })->whereIn('role', Role::values())->name('role-login');

    // Same dev/demo convenience, generalized to the Tan90 module RBAC
    // (Master Data / BOM, Recipe & Costing) instead of the GRN enum above —
    // logs in the first seeded user holding that tan90_roles.code and sends
    // them to whichever module's dashboard owns that role. Shared codes
    // (Super Admin/Plant/Auditor, seeded by more than one module) default to
    // Master Data's dashboard; the sidebar nav reaches every other module.
    Route::get('tan90-role-login/{roleCode}', function (string $roleCode) {
        $role = App\Models\Tan90\MasterData\Role::where('code', $roleCode)->firstOrFail();
        $profile = UserProfile::where('tan90_role_id', $role->id)->firstOrFail();

        Auth::login($profile->user);
        Session::regenerate();

        $bomOnlyCodes = ['ROLE-RND', 'ROLE-FORMULATION', 'ROLE-COSTING', 'ROLE-PRODUCTION-ENG', 'ROLE-QA-APPROVER'];
        $homeRoute = in_array($roleCode, $bomOnlyCodes, true) ? 'tan90.brc.dashboard' : 'tan90.master-data.dashboard';

        return redirect(rtrim(config('app.url'), '/').route($homeRoute, [], false));
    })->name('tan90-role-login');
}

if (! app()->isProduction() || filter_var(env('APP_DEMO_MODE', false), FILTER_VALIDATE_BOOL)) {
    Route::post('demo-login/{user}', function (User $user) {
        abort_unless(str_ends_with($user->email, '@tan90.demo') && $user->access_mode === 'advanced', 403);

        Auth::login($user);
        Session::regenerate();

        return redirect()->route('workspace.index');
    })->name('demo-login');
}

// Claude OAuth routes
Route::get('oauth/claude/initiate', [ClaudeOAuthController::class, 'initiate'])->name('claude.initiate');
Route::get('oauth/claude/callback', [ClaudeOAuthController::class, 'callback'])->name('claude.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    // Claude Chat
    Route::post('api/claude/chat', 'App\Http\Controllers\ClaudeOAuthController@chat')->name('claude.chat');

    // Shared utility screens — any signed-in role, each adapts its own
    // content per role (mirrors the React prototype's RequireAuth routes).
    Volt::route('notifications', 'shared.notifications')->name('notifications');
    Volt::route('activity', 'shared.activity-log')->name('activity');
    Volt::route('activity/{entry}', 'shared.activity-detail')->name('activity.detail');
    Volt::route('settings', 'shared.settings')->name('settings');
    Volt::route('help', 'shared.help-support')->name('help');

    // One route group per role, each gated by the `role` middleware
    // (EnsureRole) — Admin bypasses every gate, every other role is
    // walled off to its own prefix, mirroring the React prototype's
    // strict per-role portal isolation.
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
        Route::post('hierarchy/positions', [HierarchyController::class, 'savePosition'])->name('hierarchy.positions.save');
        Route::get('teams', [HierarchyController::class, 'index'])->name('teams.index');
        Route::get('views', [SavedViewController::class, 'index'])->name('views.index');
        Route::get('dashboard-builder', [DashboardBuilderController::class, 'index'])->name('dashboard-builder.index');
        Route::post('dashboard-builder', [DashboardBuilderController::class, 'save'])->name('dashboard-builder.save');
        Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
        Route::get('activity/{log}', [ActivityController::class, 'show'])->name('activity.show');
    });

    Route::get('workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::get('workspace/customise', [WorkspaceController::class, 'customise'])->name('workspace.customise');
    Route::post('workspace/customise', [WorkspaceController::class, 'save'])->name('workspace.save');
});

require __DIR__.'/auth.php';
