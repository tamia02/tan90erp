<?php

namespace App\Support;

use App\Enums\Role;

// Mirrors the React prototype's src/lib/nav.ts — screens each role owns
// operationally, nothing shared across roles per the client: a role's
// sidebar shows its own module only, plus the same 4 shared utility
// screens every role gets (each adapts its own content per role).
class RoleNavigation
{
    private const SHARED_SCREENS = [
        ['label' => 'Notifications', 'route' => 'notifications', 'icon' => 'bell'],
        ['label' => 'Activity Log', 'route' => 'activity', 'icon' => 'history'],
        ['label' => 'Settings', 'route' => 'settings', 'icon' => 'settings'],
        ['label' => 'Help & Support', 'route' => 'help', 'icon' => 'help-circle'],
    ];

    // Roles whose dashboard already has the full activity feed merged in —
    // the standalone Activity Log screen would just be a redundant, separate
    // copy of the same data, so it's dropped from their sidebar.
    private const ACTIVITY_MERGED_INTO_DASHBOARD = [
        Role::Guard, Role::Vendor, Role::StoreExec, Role::Qc, Role::StoreManager, Role::Finance,
    ];

    /**
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    public static function forRole(Role $role): array
    {
        $shared = in_array($role, self::ACTIVITY_MERGED_INTO_DASHBOARD, true)
            ? array_values(array_filter(self::SHARED_SCREENS, fn ($screen) => $screen['route'] !== 'activity'))
            : self::SHARED_SCREENS;

        return [...self::ownScreens($role), ...$shared];
    }

    /**
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    private static function ownScreens(Role $role): array
    {
        return match ($role) {
            Role::Guard => [
                ['label' => 'Guard Dashboard', 'route' => 'guard.dashboard', 'icon' => 'gauge'],
                ['label' => 'Bill Scan', 'route' => 'guard.scan', 'icon' => 'scan-line'],
                ['label' => 'Guard Entries', 'route' => 'guard.entries', 'icon' => 'clipboard-list'],
            ],
            Role::Vendor => [
                ['label' => 'Vendor Dashboard', 'route' => 'vendor.dashboard', 'icon' => 'gauge'],
                ['label' => 'My Submissions', 'route' => 'vendor.submissions', 'icon' => 'search'],
                ['label' => 'Stock Update', 'route' => 'vendor.stock', 'icon' => 'boxes'],
            ],
            Role::StoreExec => [
                ['label' => 'Dashboard', 'route' => 'unloading.dashboard', 'icon' => 'gauge'],
                ['label' => 'Loading Desk', 'route' => 'unloading.loading-desk', 'icon' => 'truck'],
                ['label' => 'Unloading Desk', 'route' => 'unloading.desk', 'icon' => 'warehouse'],
                ['label' => 'Dock Scheduling', 'route' => 'unloading.dock-scheduling', 'icon' => 'calendar-clock'],
                ['label' => 'History', 'route' => 'unloading.history', 'icon' => 'history'],
            ],
            Role::Qc => [
                ['label' => 'Dashboard', 'route' => 'qc.dashboard', 'icon' => 'gauge'],
                ['label' => 'QC Queue', 'route' => 'qc.queue', 'icon' => 'package-search'],
                ['label' => 'History', 'route' => 'qc.history', 'icon' => 'history'],
                ['label' => 'Quality Holds', 'route' => 'qc.holds', 'icon' => 'shield-alert'],
            ],
            Role::StoreManager => [
                ['label' => 'Dashboard', 'route' => 'grn.dashboard', 'icon' => 'gauge'],
                ['label' => 'GRN Check', 'route' => 'grn.check', 'icon' => 'clipboard-check'],
                ['label' => 'GRN Register', 'route' => 'grn.register', 'icon' => 'layout-list'],
                ['label' => 'Stock Balance', 'route' => 'grn.stock-balance', 'icon' => 'package-search'],
                ['label' => 'Stock Ledger', 'route' => 'grn.ledger', 'icon' => 'book-open'],
                ['label' => 'Shelf & Bin', 'route' => 'grn.bins', 'icon' => 'warehouse'],
                ['label' => 'Validation Issues', 'route' => 'validation.issues', 'icon' => 'list-checks'],
            ],
            Role::Finance => [
                ['label' => 'Dashboard', 'route' => 'finance.dashboard', 'icon' => 'gauge'],
                ['label' => 'Finance Review', 'route' => 'finance.review', 'icon' => 'file-text'],
                ['label' => 'Vendor Claims', 'route' => 'finance.claims', 'icon' => 'receipt'],
                ['label' => 'Reports', 'route' => 'finance.reports', 'icon' => 'trending-up'],
            ],
            Role::Admin => [
                ['label' => 'Command Center', 'route' => 'command-center', 'icon' => 'layout-grid'],
                ['label' => 'Overview', 'route' => 'admin.dashboard', 'icon' => 'gauge'],
                ['label' => 'Users', 'route' => 'admin.users', 'icon' => 'users'],
                ['label' => 'SKU Master', 'route' => 'admin.sku', 'icon' => 'package'],
                ['label' => 'Vendor Master', 'route' => 'admin.vendors', 'icon' => 'truck'],
                ['label' => 'Supplier Scorecards', 'route' => 'admin.vendor-scorecard', 'icon' => 'bar-chart-2'],
                ['label' => 'PO Master', 'route' => 'admin.po', 'icon' => 'file-text'],
                ['label' => 'RFQs', 'route' => 'admin.rfq', 'icon' => 'file-question'],
                ['label' => 'Quote Comparison', 'route' => 'admin.quote-comparison', 'icon' => 'scale'],
                ['label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'link'],
                ['label' => 'Reports', 'route' => 'admin.reports', 'icon' => 'trending-up'],
            ],
        };
    }
}
