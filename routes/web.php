<?php

use App\Enums\Role;
use App\Http\Controllers\AccessControl\AccessPeopleController;
use App\Http\Controllers\AccessControl\AccessRoleController;
use App\Http\Controllers\AccessControl\AccessSimulatorController;
use App\Http\Controllers\AccessControl\ActivityController;
use App\Http\Controllers\AccessControl\DashboardBuilderController;
use App\Http\Controllers\AccessControl\HierarchyController;
use App\Http\Controllers\AccessControl\SavedViewController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\ClaudeOAuthController;
use App\Http\Controllers\Forge\BatchController;
use App\Http\Controllers\Forge\DeviationController;
use App\Http\Controllers\Forge\FinalQcController;
use App\Http\Controllers\Forge\ForgeDashboardController;
use App\Http\Controllers\Forge\FreezerController;
use App\Http\Controllers\Forge\MachineController;
use App\Http\Controllers\Forge\YieldAnalysisController;
use App\Http\Controllers\Forge\ProductionPlanController;
use App\Http\Controllers\Forge\ProductionFlowController;
use App\Http\Controllers\Forge\ProductionModuleController;
use App\Http\Controllers\Forge\ForgeTutorialController;
use App\Http\Controllers\Forge\ProductionMappingController;
use App\Http\Controllers\Forge\MaterialRequestController;
use App\Http\Controllers\Forge\BundleController;
use App\Http\Controllers\Flow\FinanceHandoffController;
use App\Http\Controllers\Forge\ProductionOperationController;
use App\Http\Controllers\Forge\QualityHoldController;
use App\Http\Controllers\Forge\WastageController;
use App\Http\Controllers\Forge\ForgeExportController;
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
use App\Models\QcEvidenceImage;
use App\Models\GateEntry;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use App\Models\VendorSubmission;
use App\Models\PurchaseOrder;
use App\Models\GrnRecord;
use App\Services\PurchaseOrderDocumentService;
use App\Services\VendorBillDocumentService;
use App\Support\VendorIdentity;
use App\Support\RoleCapability;
use App\Support\ReportColumns;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');
Route::redirect('public', '/login');
// Same-origin active-form heartbeat. Never redirect an expired session or cache
// this response: a stale login page must not be mistaken for a valid session.
Route::get('session/check', function (\Illuminate\Http\Request $request) {
    return response()->json($request->user()
        ? ['user_id' => (string) $request->user()->id, 'csrf_token' => csrf_token()]
        : ['message' => 'Your session has expired. Sign in again before saving.'],
        $request->user() ? 200 : 401,
        ['Cache-Control' => 'no-store, private']);
})->name('session.check');
// Livewire uses this URI for POST updates; a browser opening it directly
// should return to the app instead of displaying Laravel's 405 response.
Route::get('livewire/update', fn () => redirect('/login'));

// A vendor bill can be shared with the receiving team or the driver without
// exposing the authenticated portal. The URL is signed and expires after 24
// hours; it renders the same server-generated PDF as the authenticated route.
Route::get('shared/vendor-bills/{submission}/pdf', function (VendorSubmission $submission) {
    $pdf = Pdf::loadView('pdf.vendor-bill', app(VendorBillDocumentService::class)->data($submission));
    $billReference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($submission->invoice_number ?: 'SUB-'.$submission->id));
    $filename = 'TAN90-BILL-'.trim($billReference ?: 'SUB-'.$submission->id, '-').'.pdf';

    return response($pdf->output(), 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Cache-Control' => 'private, max-age=0, must-revalidate',
    ]);
})->middleware('signed')->name('vendor.bill.share');

Route::get('shared/vendor-bills/{submission}/excel', function (VendorSubmission $submission) {
    $html = view('pdf.vendor-bill', app(VendorBillDocumentService::class)->data($submission))->render();
    $billReference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($submission->invoice_number ?: 'SUB-'.$submission->id));
    return \App\Support\HtmlToXlsx::download($html, 'TAN90-BILL-'.trim($billReference ?: 'SUB-'.$submission->id, '-'));
})->middleware('signed')->name('vendor.bill.share.excel');

Route::post('zoho/webhook/purchase-order', [ZohoWebhookController::class, 'purchaseOrder'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('zoho.webhook.purchase-order');

Route::post('logout', function (Logout $logout) {
    $logout();

    return redirect('/login');
})->middleware('auth')->name('logout');

// Demo login shortcuts — every route below logs a browser in as an
// synthetic @tan90.demo accounts with zero credentials. These shortcuts are
// restricted to local, testing, and explicitly enabled staging environments;
// staging contains copied business records and must not select real users.
Route::get('role-login/{role}', function (string $role) {
    $role = Role::from($role);
    $user = User::query()
        ->where('role', $role->value)
        ->whereIn('email', config('app.demo_login_emails', []))
        ->where('is_active', true)
        ->first();

    if (! $user) {
        return redirect()->route('login')->with('status', "No active {$role->label()} account is configured. Create an active user with this role, then sign in with that account.");
    }

    Auth::login($user);
    Session::regenerate();

    return redirect(rtrim(config('app.url'), '/').route($role->homeRouteName(), [], false));
})->whereIn('role', Role::values())->name('role-login')->middleware('demo-logins');

Route::get('tan90-role-login/{roleCode}', function (string $roleCode) {
    $role = App\Models\Tan90\MasterData\Role::query()
        ->where('code', $roleCode)
        ->where('status', 'active')
        ->first();

    $profile = $role
        ? UserProfile::query()
            ->where('tan90_role_id', $role->id)
            ->whereHas('user', fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('email', config('app.demo_login_emails', [])))
            ->with('user')
            ->first()
        : null;

    if (! $profile) {
        $roleName = $role?->name ?? 'Requested role';

        return redirect()->route('login')->with('status', "No active {$roleName} account is configured. Create an active user with this role, then sign in with that account.");
    }

    Auth::login($profile->user);
    Session::regenerate();

    $bomOnlyCodes = ['ROLE-RND', 'ROLE-FORMULATION', 'ROLE-COSTING', 'ROLE-PRODUCTION-ENG', 'ROLE-QA-APPROVER'];
    $homeRoute = in_array($roleCode, $bomOnlyCodes, true) ? 'tan90.brc.dashboard' : 'tan90.master-data.dashboard';

    return redirect(rtrim(config('app.url'), '/').route($homeRoute, [], false));
})->name('tan90-role-login')->middleware('demo-logins');

Route::get('demo-user-login/{user}', function (User $user) {
    abort_unless($user->is_active && in_array($user->email, config('app.demo_login_emails', []), true), 404);

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
})->name('demo-user-login')->middleware('demo-logins');

Route::post('demo-login/{user}', function (User $user) {
    abort_unless($user->is_active && in_array($user->email, config('app.demo_login_emails', []), true) && $user->access_mode === 'advanced', 404);

    Auth::login($user);
    Session::regenerate();

    return redirect()->route('workspace.index');
})->name('demo-login')->middleware('demo-logins');

Route::get('oauth/claude/initiate', [ClaudeOAuthController::class, 'initiate'])->name('claude.initiate');
Route::get('oauth/claude/callback', [ClaudeOAuthController::class, 'callback'])->name('claude.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    Route::post('api/claude/chat', 'App\Http\Controllers\ClaudeOAuthController@chat')->name('claude.chat');

    Volt::route('notifications', 'shared.notifications')->name('notifications');
    Volt::route('visitor-approvals', 'shared.visitor-approvals')->name('visitor-approvals');
    Volt::route('procurement/overview', 'shared.procurement-overview')->name('procurement.overview');
    Volt::route('activity', 'shared.activity-log')->name('activity');
    Volt::route('activity/{entry}', 'shared.activity-detail')->name('activity.detail');
    Volt::route('chcek', 'shared.po-tracker')->name('po.tracker');
    // Scanned from the QR on a PO PDF: vendors see their PO, staff the PO tracker.
    Route::get('po/{poNumber}/open', function (string $poNumber) {
        return auth()->user()->role === \App\Enums\Role::Vendor
            ? redirect('/vendor/purchase-orders?po='.urlencode($poNumber))
            : redirect()->route('po.tracker', ['po' => $poNumber]);
    })->where('poNumber', '.+')->name('po.open');
    Volt::route('gate-entries', 'shared.gate-entries-index')->name('gate-entries.index');
    Route::middleware('role:guard,vendor,admin,storeManager,storeExec,qc,finance')->get('gate-entries/{entry}/document/{document}', function (GateEntry $entry, string $document) {
        $user = auth()->user();
        $isVendorOwner = $user->role === Role::Vendor && $entry->vendor_name === $user->name;
        $isOperationalRole = $user->isAdmin() || in_array($user->role, [Role::Guard, Role::StoreManager, Role::StoreExec, Role::Qc, Role::Finance], true);

        abort_unless($isVendorOwner || $isOperationalRole, 403);

        if ($document === 'bill') {
            $path = $entry->bill_document_path;
            $disk = config('filesystems.default');
        } else {
            abort_unless($user->isAdmin() || ($user->role !== Role::Vendor && in_array($user->role, [Role::StoreManager, Role::StoreExec, Role::Qc], true)), 403);
            $path = $entry->unloadingRecord?->pod_document_path;
            $disk = 'local';
        }

        abort_unless(in_array($document, ['bill', 'pod'], true) && $path && Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path);
    })->whereIn('document', ['bill', 'pod'])->name('gate-entries.document');
    Volt::route('gate-entries/{entry}', 'shared.gate-entry-detail')->name('gate-entries.show');
    Volt::route('settings', 'shared.settings')->name('settings');
    // Master Data > Custom Fields: Super Admin, or anyone given 'custom_fields.manage' (checked in the page).
    Volt::route('custom-fields', 'admin.custom-fields')->name('custom-fields.index');
    // Terms & Notes by vendor / customer category: Super Admin + Store Manager (checked in the page).
    Volt::route('terms-notes', 'admin.terms-templates')->name('terms-templates.index');
    Volt::route('help', 'shared.help-support')->name('help');

    Route::middleware('role:guard')->prefix('guard')->name('guard.')->group(function () {
        Volt::route('/', 'guard.dashboard')->name('dashboard');
        Volt::route('scan', 'guard.bill-scan')->name('scan');
        Volt::route('inside', 'guard.inside')->name('inside');
        Volt::route('entries', 'guard.entries')->name('entries');
        Volt::route('entries/{entry}', 'guard.entry-detail')->name('entries.show');
    });

    Route::middleware('role:vendor')->prefix('vendor')->name('vendor.')->group(function () {
        Volt::route('/', 'vendor.dashboard')->name('dashboard');
        Volt::route('submissions', 'vendor.submissions')->name('submissions');
        Volt::route('stock', 'vendor.stock-update')->name('stock');
        Volt::route('submissions/{submission}/activity', 'vendor.submission-activity')->name('submission-activity');
        Volt::route('purchase-orders', 'vendor.purchase-orders')->name('purchase-orders');
        Volt::route('grn/{gate}', 'vendor.grn-report')->name('grn-report');
        Volt::route('claims', 'vendor.claims')->name('claims');
        Route::get('purchase-orders/{purchaseOrder:po_number}/pdf', function (PurchaseOrder $purchaseOrder) {
            abort_unless($purchaseOrder->vendor_name === auth()->user()->name && $purchaseOrder->isReleased(), 403);
            $pdf = Pdf::loadView('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->data($purchaseOrder));

            return response()->streamDownload(fn () => print ($pdf->output()), "{$purchaseOrder->po_number}.pdf", [
                'Content-Type' => 'application/pdf',
            ]);
        })->name('po.pdf');
        Route::get('purchase-orders/{purchaseOrder:po_number}/excel', function (PurchaseOrder $purchaseOrder) {
            abort_unless($purchaseOrder->vendor_name === auth()->user()->name && $purchaseOrder->isReleased(), 403);
            $html = view('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->data($purchaseOrder))->render();
            return \App\Support\HtmlToXlsx::download($html, $purchaseOrder->po_number);
        })->name('po.excel');
    });

    // GRN is the vendor-facing payment/reconciliation document. The same
    // report is available as PDF and Excel-compatible workbook to the vendor
    // and the operational roles that own receipt closure.
    Route::middleware('role:vendor,admin,storeManager,storeExec,qc')->group(function () {
        Route::get('grn-reports/{gate}/pdf', function (GateEntry $gate, \Illuminate\Http\Request $request) {
            $user = auth()->user();
            $isVendorOwner = $user->role === Role::Vendor && (VendorIdentity::names($user)->contains($gate->vendor_name) || VendorIdentity::isShared($user));
            $isOperationalRole = $user->isAdmin() || in_array($user->role, [Role::StoreManager, Role::StoreExec, Role::Qc], true);
            abort_unless($isVendorOwner || $isOperationalRole, 403);
            RoleCapability::requireExport($isVendorOwner ? 'own_grn' : 'grn');
            $gate->load(['grnRecord.lines', 'qcResult', 'qcResult.lines']);
            abort_unless($gate->grnRecord?->posted, 404);
            // Operational users may export the internal GRN while resolving a
            // hold; vendors only receive the final payment basis after hold is 0.
            if ($isVendorOwner) {
                abort_unless((int) ($gate->grnRecord?->qc_hold_qty ?? 0) === 0, 404);
            }

            $columns = ReportColumns::selectedGrn($request->query('columns'));
            $reportRows = ReportColumns::grnRows($gate, $gate->grnRecord);
            $pdf = Pdf::loadView('pdf.grn-report', ['gate' => $gate, 'grn' => $gate->grnRecord, 'qc' => $gate->qcResult, 'columns' => $columns, 'columnLabels' => ReportColumns::GRN, 'reportRows' => $reportRows]);
            $reference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($gate->po_number ?: $gate->gate_no));

            return response()->streamDownload(fn () => print ($pdf->output()), 'TAN90-GRN-'.trim($reference, '-').'.pdf', ['Content-Type' => 'application/pdf']);
        })->name('grn.export.pdf');

        Route::get('grn-reports/{gate}/excel', function (GateEntry $gate, \Illuminate\Http\Request $request) {
            $user = auth()->user();
            $isVendorOwner = $user->role === Role::Vendor && (VendorIdentity::names($user)->contains($gate->vendor_name) || VendorIdentity::isShared($user));
            $isOperationalRole = $user->isAdmin() || in_array($user->role, [Role::StoreManager, Role::StoreExec, Role::Qc], true);
            abort_unless($isVendorOwner || $isOperationalRole, 403);
            RoleCapability::requireExport($isVendorOwner ? 'own_grn' : 'grn');
            $gate->load(['grnRecord.lines', 'qcResult', 'qcResult.lines']);
            abort_unless($gate->grnRecord?->posted, 404);
            if ($isVendorOwner) {
                abort_unless((int) ($gate->grnRecord?->qc_hold_qty ?? 0) === 0, 404);
            }
            $reference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($gate->po_number ?: $gate->gate_no));

            $columns = ReportColumns::selectedGrn($request->query('columns'));
            $reportRows = ReportColumns::grnRows($gate, $gate->grnRecord);
            return \App\Support\HtmlToXlsx::download(
                view('reports.grn-excel', ['gate' => $gate, 'grn' => $gate->grnRecord, 'qc' => $gate->qcResult, 'columns' => $columns, 'columnLabels' => ReportColumns::GRN, 'reportRows' => $reportRows])->render(),
                'TAN90-GRN-'.trim($reference, '-'),
            );
        })->name('grn.export.excel');
    });

    // Vendor dispatch documents are local source records for Guard, Store
    // Manager, Store Executive, and QC; keep the download authorization
    // scoped to the submitting vendor or the operational roles.
    Route::middleware('role:vendor,guard,admin,storeManager,storeExec,qc')->prefix('vendor')->name('vendor.')->group(function () {
    Route::get('submissions/{submission}/document/{document}', function (VendorSubmission $submission, string $document) {
            $user = auth()->user();
            $canView = $user->role === Role::Vendor
                ? $submission->vendor_name === $user->name
                : ($user->isAdmin() || in_array($user->role, [Role::Guard, Role::StoreManager, Role::StoreExec, Role::Qc], true));

            abort_unless($canView, 403);

            $path = match ($document) {
                'invoice' => $submission->invoice_document_path,
                'eway' => $submission->eway_bill_document_path,
                'lr' => $submission->lr_pod_document_path,
                default => null,
            };

            abort_unless($path && Storage::disk('local')->exists($path), 404);

            return Storage::disk('local')->download($path);
        })->whereIn('document', ['invoice', 'eway', 'lr'])->name('submission.document');

        Route::get('submissions/{submission}/bill-pdf', function (VendorSubmission $submission) {
            $user = auth()->user();
            $canView = $user->role === Role::Vendor
                ? $submission->vendor_name === $user->name
                : ($user->isAdmin() || in_array($user->role, [Role::Guard, Role::StoreManager, Role::StoreExec, Role::Qc], true));

            abort_unless($canView, 403);

            $pdf = Pdf::loadView('pdf.vendor-bill', app(VendorBillDocumentService::class)->data($submission));
            $billReference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($submission->invoice_number ?: 'SUB-'.$submission->id));
            $filename = 'TAN90-BILL-'.trim($billReference ?: 'SUB-'.$submission->id, '-').'.pdf';

            return response()->streamDownload(fn () => print ($pdf->output()), $filename, [
                'Content-Type' => 'application/pdf',
            ]);
        })->name('submission.pdf');
        Route::get('submissions/{submission}/bill-excel', function (VendorSubmission $submission) {
            $user = auth()->user();
            $canView = $user->role === Role::Vendor
                ? $submission->vendor_name === $user->name
                : ($user->isAdmin() || in_array($user->role, [Role::Guard, Role::StoreManager, Role::StoreExec, Role::Qc], true));
            abort_unless($canView, 403);
            $html = view('pdf.vendor-bill', app(VendorBillDocumentService::class)->data($submission))->render();
            $reference = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($submission->invoice_number ?: 'SUB-'.$submission->id));
            return \App\Support\HtmlToXlsx::download($html, 'TAN90-BILL-'.trim($reference ?: 'SUB-'.$submission->id, '-'));
        })->name('submission.excel');
    });

    Route::middleware('role:storeExec')->prefix('unloading')->name('unloading.')->group(function () {
        Volt::route('/', 'unloading.dashboard')->name('dashboard');
        // Keep old bookmarks working, but keep the left navigation and workflow
        // on one inward task surface: Unloading Desk contains every stage.
        Route::redirect('loading-desk', '/unloading/desk')->name('loading-desk');
        Volt::route('desk', 'unloading.desk')->name('desk');
        Volt::route('outward', 'unloading.outward')->name('outward');
        Volt::route('history', 'unloading.history')->name('history');
        Volt::route('dock-scheduling', 'unloading.dock-scheduling')->name('dock-scheduling');
    });

    // Store Manager can hand a QC-held receipt to the shared Putaway queue;
    // Store Executive remains the operational owner of the physical action.
    Route::middleware('role:storeManager,storeExec')->prefix('unloading')->name('unloading.')->group(function () {
        Volt::route('putaway', 'unloading.putaway')->name('putaway');
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

    Route::get('qc/evidence/{evidenceImage}', function (QcEvidenceImage $evidenceImage) {
        abort_unless(Storage::disk('local')->exists($evidenceImage->image_path), 404);

        return Storage::disk('local')->download($evidenceImage->image_path);
    })->middleware('role:qc,admin,storeManager,finance,storeExec')->name('qc.evidence-image');

    Route::middleware('role:storeManager')->prefix('grn')->name('grn.')->group(function () {
        Volt::route('/', 'grn.dashboard')->name('dashboard');
        Volt::route('check', 'grn.check')->name('check');
        Volt::route('register', 'grn.register')->name('register');
        Volt::route('stock-balance', 'grn.stock-balance')->name('stock-balance');
        Route::redirect('ledger', 'stock-balance')->name('ledger');
        Volt::route('ledger/history', 'grn.ledger-history')->name('ledger-history');
        Volt::route('bins', 'grn.shelf-bin')->name('bins');
    });

    Route::middleware('role:admin,storeManager,storeExec,qc')->prefix('grn')->name('grn.')->group(function () {
        Volt::route('reports/{grn}', 'grn.report')->name('report');
    });

    // Vendor bill file tagged to a GRN (2026-10-13).
    Route::middleware('role:admin,storeManager,finance')->get('grn/{grn}/vendor-bill', function (\App\Models\GrnRecord $grn) {
        abort_unless($grn->vendor_bill_path && \Illuminate\Support\Facades\Storage::exists($grn->vendor_bill_path), 404);

        return \Illuminate\Support\Facades\Storage::response($grn->vendor_bill_path);
    })->name('grn.vendor-bill');

    Route::middleware('role:admin,storeManager')->prefix('grn')->name('grn.')->group(function () {
        Volt::route('po-control-tower', 'shared.po-control-tower')->name('po-control-tower');
    });

    Route::middleware('role:storeManager')->group(function () {
        Volt::route('validation', 'validation-issues')->name('validation.issues');
        Volt::route('entry-approvals', 'store-manager.entry-approvals')->name('store-manager.entry-approvals');
    });

    // PO creation stands in for Procurement for now -- Admin retains it too
    // since PO Master was originally an Admin-only screen.
    Route::middleware('role:admin,storeManager')->group(function () {
        Volt::route('admin/po/create', 'admin.po-master')->name('admin.po.create');
        Route::get('admin/po/{purchaseOrder:po_number}', [\App\Http\Controllers\PurchaseOrderController::class, 'show'])->name('admin.po.show');
        Volt::route('admin/po', 'admin.po-master')->name('admin.po');
        Route::get('admin/po/preview/{token}', function (string $token) {
            abort_unless(\Illuminate\Support\Str::isUuid($token), 404);
            $snapshot = \Illuminate\Support\Facades\Cache::get('po-pdf-preview:'.auth()->id().':'.$token);
            abort_unless(is_array($snapshot), 404);

            $pdf = Pdf::loadView('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->previewData($snapshot));

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="purchase-order-preview.pdf"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        })->name('admin.po.draft-preview');
        Route::get('admin/po/{purchaseOrder:po_number}/pdf', function (PurchaseOrder $purchaseOrder) {
            $pdf = Pdf::loadView('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->data($purchaseOrder));

            return response()->streamDownload(fn () => print ($pdf->output()), "{$purchaseOrder->po_number}.pdf", [
                'Content-Type' => 'application/pdf',
            ]);
        })->name('admin.po.pdf');
        Route::get('admin/po/{purchaseOrder:po_number}/preview', function (PurchaseOrder $purchaseOrder) {
            $pdf = Pdf::loadView('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->data($purchaseOrder));

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$purchaseOrder->po_number.'.pdf"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        })->name('admin.po.preview');
        Route::get('admin/po/{purchaseOrder:po_number}/excel', function (PurchaseOrder $purchaseOrder) {
            $html = view('pdf.purchase-order', app(PurchaseOrderDocumentService::class)->data($purchaseOrder))->render();
            return \App\Support\HtmlToXlsx::download($html, $purchaseOrder->po_number);
        })->name('admin.po.excel');
    });

    Route::middleware('role:finance')->prefix('finance')->name('finance.')->group(function () {
        Volt::route('/', 'finance.dashboard')->name('dashboard');
        Volt::route('review', 'finance.review')->name('review');
        Volt::route('claims', 'finance.vendor-claims')->name('claims');
        Volt::route('reports', 'finance.reports')->name('reports');
        Volt::route('ap-aging', 'finance.ap-aging')->name('ap-aging');
    });

    // SKU Master: Store Manager adds the SKUs they raise POs for (client ask).
    Route::middleware('role:admin,storeManager')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('sku', 'admin.sku-master')->name('sku');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('/', 'admin.overview')->name('dashboard');
        Volt::route('users', 'admin.users')->name('users');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
        Volt::route('vendors', 'admin.vendor-master')->name('vendors');
        Volt::route('rfq', 'admin.rfq')->name('rfq');
        Volt::route('quote-comparison', 'admin.quote-comparison')->name('quote-comparison');
        Volt::route('vendor-scorecard', 'admin.vendor-scorecard')->name('vendor-scorecard');
        Volt::route('supplier-claims', 'admin.supplier-claims')->name('supplier-claims');
        Volt::route('integrations', 'admin.integrations')->name('integrations');
        Volt::route('reports', 'admin.reports')->name('reports');
    });

    // Inventory: RM/PM -> Create Bundle -> FG -> Dispatch, plus Trading goods.
    Route::middleware('role:admin,storeManager')->prefix('inventory')->name('inventory.')->group(function () {
        Volt::route('/', 'inventory.stock')->name('stock');
        Volt::route('items', 'inventory.items')->name('items');
        Route::get('items/create', [\App\Http\Controllers\Inventory\InventoryItemEditorController::class, 'create'])->name('items.create');
        Route::post('items', [\App\Http\Controllers\Inventory\InventoryItemEditorController::class, 'store'])->name('items.store');
        Route::get('items/{inventoryItem}/edit', [\App\Http\Controllers\Inventory\InventoryItemEditorController::class, 'edit'])
            ->whereNumber('inventoryItem')->name('items.edit');
        Route::put('items/{inventoryItem}', [\App\Http\Controllers\Inventory\InventoryItemEditorController::class, 'update'])
            ->whereNumber('inventoryItem')->name('items.update');
        Route::get('items/{inventoryItem}', [\App\Http\Controllers\Inventory\ItemHistoryController::class, 'show'])
            ->whereNumber('inventoryItem')
            ->name('items.show');
        Volt::route('recipes', 'inventory.recipes')->name('recipes');
        Volt::route('production/create', 'inventory.production')->name('production.create');
        Volt::route('production', 'inventory.bundle-index')->name('production');
        Route::get('bundles/{production}', [\App\Http\Controllers\Inventory\InventoryBundleController::class, 'show'])->whereNumber('production')->name('bundles.show');
        Route::get('bundles/{production}/edit', [\App\Http\Controllers\Inventory\InventoryBundleController::class, 'edit'])->whereNumber('production')->name('bundles.edit');
        Route::put('bundles/{production}', [\App\Http\Controllers\Inventory\InventoryBundleController::class, 'update'])->whereNumber('production')->name('bundles.update');
        Volt::route('dispatch', 'inventory.dispatch')->name('dispatch');
        Route::get('outward/{shipment}', [\App\Http\Controllers\Inventory\OutwardShipmentController::class, 'show'])
            ->whereNumber('shipment')->name('outward.show');
        Route::get('outward/{shipment}/tax-invoice', function (\App\Models\Outward\OutwardShipment $shipment) {
            abort_unless($shipment->document_type === 'tax_invoice', 404);
            $shipment->load(['lines.item', 'fromLocation', 'toLocation', 'creator']);
            $pdf = Pdf::loadView('pdf.outward-document', ['kind' => 'invoice', 'shipment' => $shipment->load(['lines.item', 'lines.packagingType', 'fromLocation', 'toLocation', 'creator'])]);

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($shipment->invoice_number ?: $shipment->number).'.pdf"']);
        })->name('outward.tax-invoice');
        // Delivery Challan / Transfer Order in the Zoho Books layout, opened as a PDF.
        Route::get('outward/{shipment}/challan', function (\App\Models\Outward\OutwardShipment $shipment) {
            abort_unless($shipment->challan_number, 404);
            $pdf = Pdf::loadView('pdf.outward-document', ['kind' => 'challan', 'shipment' => $shipment->load(['lines.item', 'lines.packagingType', 'fromLocation', 'toLocation', 'creator'])]);

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$shipment->challan_number.'.pdf"']);
        })->name('outward.challan');
        Route::get('outward/{shipment}/transfer-order', function (\App\Models\Outward\OutwardShipment $shipment) {
            abort_unless($shipment->purpose === 'transfer', 404);
            $pdf = Pdf::loadView('pdf.outward-document', ['kind' => 'transfer', 'shipment' => $shipment->load(['lines.item', 'lines.packagingType', 'fromLocation', 'toLocation', 'creator'])]);

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($shipment->invoice_number ?: $shipment->number).'.pdf"']);
        })->name('outward.transfer-order');
        Route::get('outward/{shipment}/{document}', function (\App\Models\Outward\OutwardShipment $shipment, string $document) {
            $path = $shipment->{$document.'_file'};
            abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

            return \Illuminate\Support\Facades\Storage::disk('local')->response($path);
        })->whereIn('document', ['invoice', 'irn', 'ewb'])->name('outward.file');
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
        Route::post('people/{user}/job-roles', [AccessPeopleController::class, 'updateJobRoles'])->name('people.job-roles');
        Route::delete('people/{user}/roles/{role}', [AccessPeopleController::class, 'removeRole'])->name('people.remove-role');

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

    Route::prefix('forge')->name('forge.')->middleware(\App\Http\Middleware\RequireProductionRun::class)->group(function () {
        Route::get('/', [ForgeDashboardController::class, 'index'])->name('dashboard');
        Route::get('plans/export', [ForgeExportController::class, 'plans'])->name('plans.export');
        Route::get('workorders/export', [ForgeExportController::class, 'workOrders'])->name('workorders.export');
        Route::get('machines/export', [ForgeExportController::class, 'machines'])->name('machines.export');
        Route::get('wastage/export', [ForgeExportController::class, 'wastage'])->name('wastage.export');
        Route::get('final-qc/export', [ForgeExportController::class, 'finalQc'])->name('final-qc.export');
        Route::get('batches/export', [ForgeExportController::class, 'batches'])->name('batches.export');
        Route::get('production-flow', [ProductionFlowController::class, 'index'])->name('production-flow');
        Route::get('modules/{step}', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'show'])
            ->where('step', 'materials|mixing|filling-sealing|quality|consumption-fg|finished-goods')
            ->name('modules.show');
        Route::get('modules/{step}/history', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'show'])
            ->where('step', 'materials|mixing|filling-sealing|quality|consumption-fg|finished-goods')->name('modules.history');
        Route::get('modules/{step}/workorders/{workOrder}/{operation}', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'workOrder'])
            ->where('step', 'filling-sealing')->where('operation','filling|sealing')->name('modules.operation');
        Route::get('modules/{step}/workorders/{workOrder}', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'workOrder'])
            ->where('step', 'materials|mixing|filling-sealing|quality|consumption-fg|finished-goods')
            ->name('modules.workorder');
        Route::get('tutorial', [ForgeTutorialController::class, 'index'])->name('tutorial');
        Route::get('mappings', [ProductionMappingController::class, 'index'])->name('mappings.index');
        Route::post('mappings', [ProductionMappingController::class, 'store'])->name('mappings.store');

        Route::prefix('plans')->name('plans.')->group(function () {
            Route::get('/', [ProductionPlanController::class, 'index'])->name('index');
            Route::post('/', [ProductionPlanController::class, 'store'])->name('store');
            Route::post('{plan}/approve', [ProductionPlanController::class, 'approve'])->name('approve');
            Route::get('{plan}', [ProductionPlanController::class, 'show'])->name('show');
            Route::put('{plan}', [ProductionPlanController::class, 'update'])->name('update');
        });

        Route::prefix('workorders')->name('workorders.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'orders'])->name('index');
            Route::get('history', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'orders'])->name('history');
            Route::get('create', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'store'])->name('store');
            Route::get('{workOrder}/review', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'review'])->name('review');
            Route::get('{workOrder}', [\App\Http\Controllers\Forge\WorkerProductionController::class, 'trace'])->name('show');
            Route::post('{workOrder}/operations/{operation}/start', [\App\Http\Controllers\Forge\OperationRunController::class, 'start'])->name('operations.start');
            Route::post('{workOrder}/runs/{run}/complete', [\App\Http\Controllers\Forge\OperationRunController::class, 'complete'])->name('operations.complete');
            Route::post('{workOrder}/runs/{run}/draft', [\App\Http\Controllers\Forge\OperationRunController::class, 'saveDraft'])->name('operations.draft');
            Route::post('{workOrder}/runs/{run}/correct', [\App\Http\Controllers\Forge\OperationRunController::class, 'correct'])->name('operations.correct');
            Route::post('{workOrder}/approve-output', [\App\Http\Controllers\Forge\OperationRunController::class, 'approveOutput'])->name('approve-output');
            Route::post('{workOrder}/release', [WorkOrderController::class, 'release'])->name('release');
            Route::post('{workOrder}/reserve-material', [WorkOrderController::class, 'reserveMaterial'])->name('reserve-material');
            Route::post('{workOrder}/issue-material', [WorkOrderController::class, 'issueMaterial'])->name('issue-material');
            Route::post('{workOrder}/material-requests', [MaterialRequestController::class, 'store'])->name('material-requests.store');
            Route::post('{workOrder}/material-requests/{materialRequest}/issue', [MaterialRequestController::class, 'issue'])->name('material-requests.issue');
            Route::post('{workOrder}/bundle', [BundleController::class, 'confirm'])->name('bundle.confirm');
            Route::post('{workOrder}/start', [WorkOrderController::class, 'start'])->name('start');
            Route::post('{workOrder}/resume-rework', [WorkOrderController::class, 'resumeRework'])->name('resume-rework');
            Route::post('{workOrder}/record-production', [WorkOrderController::class, 'recordProduction'])->name('record-production');
            Route::post('{workOrder}/mixing', [ProductionOperationController::class, 'mixing'])->name('mixing');
            Route::post('{workOrder}/process', [ProductionOperationController::class, 'process'])->name('process');
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
            Route::post('{machine}/site', [MachineController::class, 'assignSite'])->name('site');
            Route::post('{machine}/downtime', [MachineController::class, 'startDowntime'])->name('downtime');
            Route::post('{machine}/state', [MachineController::class, 'setState'])->name('state');
            Route::post('downtime/{downtime}/close', [MachineController::class, 'closeDowntime'])->name('downtime.close');
        });

        Route::prefix('freezers')->name('freezers.')->group(function () {
            Route::get('/', [FreezerController::class, 'index'])->name('index');
            Route::post('{freezer}/site', [FreezerController::class, 'assignSite'])->name('site');
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
        Route::get('zoho-sales', function (\Illuminate\Http\Request $request, \App\Services\Access\AccessControlService $access) {
            abort_unless($access->can($request->user(), 'flow.order.view'), 403);
            $type = $request->query('type', 'salesorder');
            abort_unless(in_array($type, ['salesorder', 'package', 'invoice'], true), 404);
            $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
            $search = trim((string) ($filters['q'] ?? ''));

            return view('flow.zoho-sales', [
                'type' => $type,
                'search' => $search,
                'documents' => \App\Models\ZohoSalesDocument::where('organization_id', (string) config('services.zoho_items_pull.organization_id'))
                    ->where('type', $type)->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                        $query->where('number', 'like', '%'.$search.'%')->orWhere('salesorder_number', 'like', '%'.$search.'%')->orWhere('customer_name', 'like', '%'.$search.'%');
                    }))->orderByDesc('document_date')->orderByDesc('id')->paginate(50)->withQueryString(),
            ]);
        })->name('zoho-sales');
        Route::post('finance-tasks/{task}/review', [FinanceHandoffController::class, 'review'])->name('finance-tasks.review');
        Route::get('/', [FlowDashboardController::class, 'index'])->name('dashboard');

        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::post('receive/{batch}', [InventoryController::class, 'receive'])->name('receive');
            Route::post('{lot}/putaway', [InventoryController::class, 'putaway'])->middleware(\App\Http\Middleware\RequireProductionRun::class)->name('putaway');
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
            Route::post('assign-unit', [DispatchController::class, 'assignUnit'])->name('assign-unit');
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

require __DIR__.'/logistics.php';
