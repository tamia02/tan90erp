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
        ['label' => 'Global Search & PO Tracker', 'route' => 'po.tracker', 'icon' => 'search'],
        ['label' => 'Notifications', 'route' => 'notifications', 'icon' => 'bell'],
        ['label' => 'Visitor Approvals', 'route' => 'visitor-approvals', 'icon' => 'check-circle'],
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
     * A role's own module screens without the shared utility screens - used
     * to add an extra role's section under a person's main-role menu.
     *
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    public static function ownScreensFor(Role $role): array
    {
        return self::ownScreens($role);
    }

    /**
     * @return array<int, array{label: string, route: string, icon: string}>
     */
    private static function ownScreens(Role $role): array
    {
        return match ($role) {
            Role::Guard => [
                ['label' => 'Guard Dashboard', 'route' => 'guard.dashboard', 'icon' => 'gauge'],
                ['label' => 'New Gate Pass', 'route' => 'guard.scan', 'icon' => 'scan-line'],
                ['label' => 'Past Gate Entries', 'route' => 'guard.entries', 'icon' => 'clipboard-list'],
            ],
            Role::Vendor => [
                ['label' => 'Vendor Dashboard', 'route' => 'vendor.dashboard', 'icon' => 'gauge'],
                ['label' => 'Purchase Orders', 'route' => 'vendor.purchase-orders', 'icon' => 'file-text'],
                ['label' => 'My Submissions', 'route' => 'vendor.submissions', 'icon' => 'search'],
                ['label' => 'Claims', 'route' => 'vendor.claims', 'icon' => 'receipt'],
                ['label' => 'Stock Update', 'route' => 'vendor.stock', 'icon' => 'boxes'],
            ],
            Role::StoreExec => [
                ['label' => 'Dashboard', 'route' => 'unloading.dashboard', 'icon' => 'gauge'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Loading & Unloading', 'route' => 'unloading.desk', 'icon' => 'warehouse'],
                ['label' => 'Putaway', 'route' => 'unloading.putaway', 'icon' => 'package'],
                ['label' => 'Dock Scheduling', 'route' => 'unloading.dock-scheduling', 'icon' => 'calendar-clock'],
                ['label' => 'History', 'route' => 'unloading.history', 'icon' => 'history'],
            ],
            Role::Qc => [
                ['label' => 'Dashboard', 'route' => 'qc.dashboard', 'icon' => 'gauge'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'QC Queue', 'route' => 'qc.queue', 'icon' => 'package-search'],
                ['label' => 'History', 'route' => 'qc.history', 'icon' => 'history'],
                ['label' => 'Quality Holds', 'route' => 'qc.holds', 'icon' => 'shield-alert'],
            ],
            Role::StoreManager => [
                ['label' => 'Dashboard', 'route' => 'grn.dashboard', 'icon' => 'gauge'],
                ['label' => 'PO Control Tower', 'route' => 'grn.po-control-tower', 'icon' => 'search'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Entry Approvals', 'route' => 'store-manager.entry-approvals', 'icon' => 'check-circle'],
                ['label' => 'PO Master', 'route' => 'admin.po', 'icon' => 'file-text'],
                ['label' => 'Items (Zoho)', 'route' => 'admin.sku', 'icon' => 'package'],
                ['label' => 'GRN Check', 'route' => 'grn.check', 'icon' => 'clipboard-check'],
                ['label' => 'GRN Register', 'route' => 'grn.register', 'icon' => 'layout-list'],
                ['label' => 'Stock Balance', 'route' => 'grn.stock-balance', 'icon' => 'package-search'],
                ['label' => 'Shelf & Bin', 'route' => 'grn.bins', 'icon' => 'warehouse'],
                ['label' => 'Validation Issues', 'route' => 'validation.issues', 'icon' => 'list-checks'],
                // Inventory module: SKU Master / GRN stock -> production -> dispatch.
                ['heading' => 'Inventory'],
                ['label' => 'Stock Summary', 'route' => 'inventory.stock', 'icon' => 'boxes'],
                ['label' => 'Items', 'route' => 'inventory.items', 'icon' => 'list'],
                ['label' => 'Recipes', 'route' => 'inventory.recipes', 'icon' => 'clipboard-list'],
                ['label' => 'Create Bundle', 'route' => 'inventory.production', 'icon' => 'factory'],
                ['label' => 'Outward Shipment', 'route' => 'inventory.dispatch', 'icon' => 'truck'],
            ],
            Role::Finance => [
                ['label' => 'Dashboard', 'route' => 'finance.dashboard', 'icon' => 'gauge'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Finance Review', 'route' => 'finance.review', 'icon' => 'file-text'],
                ['label' => 'Vendor Claims', 'route' => 'finance.claims', 'icon' => 'receipt'],
                ['label' => 'Reports', 'route' => 'finance.reports', 'icon' => 'trending-up'],
            ],
            Role::Admin => [
                ['label' => 'Command Center', 'route' => 'command-center', 'icon' => 'layout-grid'],
                ['label' => 'Overview', 'route' => 'admin.dashboard', 'icon' => 'gauge'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Users', 'route' => 'admin.users', 'icon' => 'users'],
                ['label' => 'Master Data', 'route' => 'tan90.master-data.dashboard', 'icon' => 'boxes'],
                ['label' => 'Items (Zoho)', 'route' => 'admin.sku', 'icon' => 'package'],
                ['label' => 'Vendor Master', 'route' => 'admin.vendors', 'icon' => 'truck'],
                ['label' => 'Supplier Scorecards', 'route' => 'admin.vendor-scorecard', 'icon' => 'bar-chart-2'],
                ['label' => 'Supplier Claims', 'route' => 'admin.supplier-claims', 'icon' => 'receipt'],
                ['label' => 'PO Master', 'route' => 'admin.po', 'icon' => 'file-text'],
                ['label' => 'RFQs', 'route' => 'admin.rfq', 'icon' => 'file-question'],
                ['label' => 'Quote Comparison', 'route' => 'admin.quote-comparison', 'icon' => 'scale'],
                ['label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'link'],
                ['label' => 'Reports', 'route' => 'admin.reports', 'icon' => 'trending-up'],
            ],
        };
    }
}
