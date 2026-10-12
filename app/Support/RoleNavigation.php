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

        return [...self::ownScreens($role), ['heading' => 'General'], ...$shared];
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
                ['heading' => 'Gate Pass'],
                ['label' => 'New Gate Pass', 'route' => 'guard.scan', 'icon' => 'scan-line'],
                ['label' => 'Vehicles Inside (Truck Out)', 'route' => 'guard.inside', 'icon' => 'log-out'],
                ['label' => 'Past Gate Entries', 'route' => 'guard.entries', 'icon' => 'clipboard-list'],
            ],
            Role::Vendor => [
                ['label' => 'Vendor Dashboard', 'route' => 'vendor.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Orders & Dispatch'],
                ['label' => 'Purchase Orders', 'route' => 'vendor.purchase-orders', 'icon' => 'file-text'],
                ['label' => 'My Submissions', 'route' => 'vendor.submissions', 'icon' => 'search'],
                ['heading' => 'Claims & Stock'],
                ['label' => 'Claims', 'route' => 'vendor.claims', 'icon' => 'receipt'],
                ['label' => 'Stock Update', 'route' => 'vendor.stock', 'icon' => 'boxes'],
            ],
            Role::StoreExec => [
                ['label' => 'Dashboard', 'route' => 'unloading.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Loading & Unloading'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Loading & Unloading', 'route' => 'unloading.desk', 'icon' => 'warehouse'],
                ['label' => 'Dock Scheduling', 'route' => 'unloading.dock-scheduling', 'icon' => 'calendar-clock'],
                ['heading' => 'Putaway & History'],
                ['label' => 'Putaway', 'route' => 'unloading.putaway', 'icon' => 'package'],
                ['label' => 'History', 'route' => 'unloading.history', 'icon' => 'history'],
            ],
            Role::Qc => [
                ['label' => 'Dashboard', 'route' => 'qc.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Quality Check'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'QC Queue', 'route' => 'qc.queue', 'icon' => 'package-search'],
                ['label' => 'History', 'route' => 'qc.history', 'icon' => 'history'],
                ['label' => 'Quality Holds', 'route' => 'qc.holds', 'icon' => 'shield-alert'],
            ],
            Role::StoreManager => [
                ['label' => 'Dashboard', 'route' => 'grn.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Purchase'],
                ['label' => 'PO Control Tower', 'route' => 'grn.po-control-tower', 'icon' => 'search'],
                ['label' => 'Purchase Orders', 'route' => 'admin.po', 'icon' => 'file-text'],
                ['label' => 'Terms & Notes', 'route' => 'terms-templates.index', 'icon' => 'file-text'],
                ['heading' => 'Gate & Approvals'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Entry Approvals', 'route' => 'store-manager.entry-approvals', 'icon' => 'check-circle'],
                ['label' => 'Validation Issues', 'route' => 'validation.issues', 'icon' => 'list-checks'],
                ['heading' => 'GRN & Stock'],
                ['label' => 'Purchase Receive (GRN)', 'route' => 'grn.check', 'icon' => 'clipboard-check'],
                ['label' => 'Purchase Receives (GRN Register)', 'route' => 'grn.register', 'icon' => 'layout-list'],
                ['label' => 'Stock Balance', 'route' => 'grn.stock-balance', 'icon' => 'package-search'],
                ['label' => 'Shelf & Bin', 'route' => 'grn.bins', 'icon' => 'warehouse'],
                // Inventory module: SKU Master / GRN stock -> production -> dispatch.
                ['heading' => 'Inventory'],
                ['label' => 'Inventory Summary', 'route' => 'inventory.stock', 'icon' => 'boxes'],
                ['label' => 'Items', 'route' => 'inventory.items', 'icon' => 'list'],
                ['label' => 'Composite Items (Recipes)', 'route' => 'inventory.recipes', 'icon' => 'clipboard-list'],
                ['label' => 'Create Bundle', 'route' => 'inventory.production', 'icon' => 'factory'],
                ['label' => 'Outward Shipment', 'route' => 'inventory.dispatch', 'icon' => 'truck'],
            ],
            Role::Finance => [
                ['label' => 'Dashboard', 'route' => 'finance.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Bills & Claims'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Bills (Finance Review)', 'route' => 'finance.review', 'icon' => 'file-text'],
                ['label' => 'Vendor Claims', 'route' => 'finance.claims', 'icon' => 'receipt'],
                ['label' => 'Reports', 'route' => 'finance.reports', 'icon' => 'trending-up'],
            ],
            Role::Admin => [
                ['label' => 'Command Center', 'route' => 'command-center', 'icon' => 'layout-grid'],
                ['label' => 'Overview', 'route' => 'admin.dashboard', 'icon' => 'gauge'],
                ['heading' => 'Purchasing'],
                ['label' => 'Purchase Orders', 'route' => 'admin.po', 'icon' => 'file-text'],
                ['label' => 'RFQs', 'route' => 'admin.rfq', 'icon' => 'file-question'],
                ['label' => 'Quote Comparison', 'route' => 'admin.quote-comparison', 'icon' => 'scale'],
                ['label' => 'Terms & Notes', 'route' => 'terms-templates.index', 'icon' => 'file-text'],
                ['heading' => 'Vendors'],
                ['label' => 'Vendors', 'route' => 'admin.vendors', 'icon' => 'truck'],
                ['label' => 'Supplier Scorecards', 'route' => 'admin.vendor-scorecard', 'icon' => 'bar-chart-2'],
                ['label' => 'Supplier Claims', 'route' => 'admin.supplier-claims', 'icon' => 'receipt'],
                ['heading' => 'Gate & Reports'],
                ['label' => 'All Gate Entries', 'route' => 'gate-entries.index', 'icon' => 'clipboard-list'],
                ['label' => 'Reports', 'route' => 'admin.reports', 'icon' => 'trending-up'],
                ['heading' => 'Setup'],
                ['label' => 'Users', 'route' => 'admin.users', 'icon' => 'users'],
                ['label' => 'Master Data', 'route' => 'tan90.master-data.dashboard', 'icon' => 'boxes'],
                ['label' => 'Custom Fields', 'route' => 'custom-fields.index', 'icon' => 'settings'],
                ['label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'link'],
            ],
        };
    }
}
