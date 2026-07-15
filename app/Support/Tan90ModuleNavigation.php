<?php

namespace App\Support;

// Sidebar nav items for Tan90-only users (Master Data / BOM roles, no GRN
// role) - same shape as RoleNavigation so layout/navigation.blade.php can
// render either with the exact same markup. Picked by current route
// (not by role) since a shared role like Super Admin moves between
// modules; whichever module's routes the user is currently on is the nav
// that renders.
class Tan90ModuleNavigation
{
    /** @return array<int, array{label: string, route: string, icon: string}> */
    public static function forMasterData(): array
    {
        return [
            ['label' => 'Command Center', 'route' => 'tan90.master-data.dashboard', 'icon' => 'gauge'],
            ['label' => 'Approval Queue', 'route' => 'tan90.master-data.approval-queue', 'icon' => 'check'],
            ['label' => 'Items', 'route' => 'tan90.master-data.index', 'icon' => 'box', 'params' => ['items']],
            ['label' => 'Vendors', 'route' => 'tan90.master-data.index', 'icon' => 'truck', 'params' => ['vendors']],
            ['label' => 'Plants', 'route' => 'tan90.master-data.index', 'icon' => 'building', 'params' => ['plants']],
            ['label' => 'Import / Export', 'route' => 'tan90.master-data.import.index', 'icon' => 'upload'],
            ['label' => 'Data Quality', 'route' => 'tan90.master-data.data-quality.index', 'icon' => 'shield'],
            ['label' => 'Audit Trail', 'route' => 'tan90.master-data.audit-logs', 'icon' => 'history'],
            ['label' => 'Settings', 'route' => 'tan90.master-data.settings.edit', 'icon' => 'settings'],
        ];
    }

    /** @return array<int, array{label: string, route: string, icon: string}> */
    public static function forBom(): array
    {
        return [
            ['label' => 'Command Center', 'route' => 'tan90.brc.dashboard', 'icon' => 'gauge'],
            ['label' => 'MRP Readiness', 'route' => 'tan90.brc.mrp-readiness.index', 'icon' => 'check'],
            ['label' => 'Recipes', 'route' => 'tan90.brc.recipes.index', 'icon' => 'flask'],
            ['label' => 'BOM Register', 'route' => 'tan90.brc.boms.index', 'icon' => 'list'],
            ['label' => 'Routings', 'route' => 'tan90.brc.routings.index', 'icon' => 'route'],
            ['label' => 'Cost Sheets', 'route' => 'tan90.brc.costing.index', 'icon' => 'dollar'],
            ['label' => 'Engineering Changes', 'route' => 'tan90.brc.eco.index', 'icon' => 'edit'],
            ['label' => 'Finished Goods', 'route' => 'tan90.brc.index', 'icon' => 'box', 'params' => ['finished-goods']],
            ['label' => 'Components', 'route' => 'tan90.brc.index', 'icon' => 'box', 'params' => ['components']],
            ['label' => 'Work Centers', 'route' => 'tan90.brc.index', 'icon' => 'building', 'params' => ['work-centers']],
            ['label' => 'Audit Trail', 'route' => 'tan90.brc.audit-logs', 'icon' => 'history'],
        ];
    }
}
