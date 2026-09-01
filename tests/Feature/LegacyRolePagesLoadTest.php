<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The original Guard/Vendor/StoreExec/QC/StoreManager/Finance/Admin pipeline
 * (Space 04 — Source to Stock) predates every later module and has never had
 * its own page-render coverage — only the underlying services (GrnPostingService,
 * GateValidationService) and two specific pages (dock-scheduling, vendor
 * submissions) are tested elsewhere. Deliberately NOT RefreshDatabase: these
 * routes are gated by the legacy role:xxx middleware against the persistent
 * demo users DatabaseSeeder creates (guard@tan90.test etc.), the same
 * approach ForgeGoldenPathTest/FlowGoldenPathTest use for their own demo
 * users. Run in isolation (--filter) so a RefreshDatabase test elsewhere in
 * the same process can't wipe that seed data out from under it.
 */
class LegacyRolePagesLoadTest extends TestCase
{
    public function test_guard_pages_load(): void
    {
        $user = User::where('email', 'guard@tan90.test')->firstOrFail();

        foreach (['guard.dashboard', 'guard.scan', 'guard.entries'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_vendor_pages_load(): void
    {
        $user = User::where('email', 'vendor@tan90.test')->firstOrFail();

        foreach (['vendor.dashboard', 'vendor.submissions', 'vendor.stock'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_unloading_pages_load(): void
    {
        $user = User::where('email', 'storeexec@tan90.test')->firstOrFail();

        foreach (['unloading.dashboard', 'unloading.loading-desk', 'unloading.desk', 'unloading.history', 'unloading.dock-scheduling'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_qc_pages_load(): void
    {
        $user = User::where('email', 'qc@tan90.test')->firstOrFail();

        foreach (['qc.dashboard', 'qc.queue', 'qc.history', 'qc.holds'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_grn_and_validation_pages_load(): void
    {
        $user = User::where('email', 'storemanager@tan90.test')->firstOrFail();

        foreach (['grn.dashboard', 'grn.check', 'grn.register', 'grn.stock-balance', 'grn.ledger', 'grn.bins', 'validation.issues'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_finance_pages_load(): void
    {
        $user = User::where('email', 'finance@tan90.test')->firstOrFail();

        foreach (['finance.dashboard', 'finance.review', 'finance.claims', 'finance.reports'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_admin_pages_load(): void
    {
        $user = User::where('email', 'admin@tan90.test')->firstOrFail();

        foreach ([
            'admin.dashboard', 'admin.users', 'admin.sku', 'admin.vendors', 'admin.po',
            'admin.rfq', 'admin.quote-comparison', 'admin.vendor-scorecard',
            'admin.integrations', 'admin.reports', 'command-center',
        ] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }
}
