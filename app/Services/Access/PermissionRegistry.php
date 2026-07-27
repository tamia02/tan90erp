<?php

namespace App\Services\Access;

class PermissionRegistry
{
    public function permissions(): array
    {
        $base = [
            ['key' => 'guard.module.access', 'module' => 'Guard', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Guard module'],
            ['key' => 'vendor.module.access', 'module' => 'Vendor', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Vendor module'],
            ['key' => 'unloading.module.access', 'module' => 'Unloading', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Unloading module'],
            ['key' => 'qc.module.access', 'module' => 'QC', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access QC module'],
            ['key' => 'grn.module.access', 'module' => 'GRN', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access GRN module'],
            ['key' => 'finance.module.access', 'module' => 'Finance', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Finance module'],
            ['key' => 'admin.module.access', 'module' => 'Admin', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Admin module'],
            ['key' => 'master_data.module.access', 'module' => 'Master Data', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Master Data module'],
            ['key' => 'brc.module.access', 'module' => 'BOM Recipe Costing', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access BRC module'],
            ['key' => 'access_control.module.access', 'module' => 'Access Control', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Control module'],
            ['key' => 'workspace.module.access', 'module' => 'Workspace', 'screen' => null, 'category' => 'module', 'action' => 'access', 'label' => 'Access Workspace module'],
            ['key' => 'access.roles.view', 'module' => 'Access Control', 'screen' => 'Roles', 'category' => 'access_admin', 'action' => 'view', 'label' => 'View role list', 'route_name' => 'access.roles.index'],
            ['key' => 'access.roles.manage', 'module' => 'Access Control', 'screen' => 'Roles', 'category' => 'access_admin', 'action' => 'manage', 'label' => 'Create and edit lower roles', 'route_name' => 'access.roles.create', 'is_sensitive' => true],
            ['key' => 'access.people.view', 'module' => 'Access Control', 'screen' => 'People', 'category' => 'access_admin', 'action' => 'view', 'label' => 'View people', 'route_name' => 'access.people.index'],
            ['key' => 'access.people.manage', 'module' => 'Access Control', 'screen' => 'People', 'category' => 'access_admin', 'action' => 'assign', 'label' => 'Assign/revoke user roles', 'route_name' => 'access.people.index', 'is_sensitive' => true],
            ['key' => 'access.hierarchy.view', 'module' => 'Access Control', 'screen' => 'Hierarchy', 'category' => 'access_admin', 'action' => 'view', 'label' => 'View hierarchy', 'route_name' => 'access.hierarchy.index'],
            ['key' => 'access.activity.view', 'module' => 'Access Control', 'screen' => 'Activity', 'category' => 'access_admin', 'action' => 'view', 'label' => 'View access activity', 'route_name' => 'access.activity.index'],
            ['key' => 'views.use_assigned', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'use', 'label' => 'Use assigned saved views'],
            ['key' => 'views.create_personal', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'create', 'label' => 'Create personal saved views'],
            ['key' => 'views.manage_role', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'manage', 'label' => 'Manage role saved views'],
            ['key' => 'views.manage_team', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'manage', 'label' => 'Manage team saved views'],
            ['key' => 'views.manage_user', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'manage', 'label' => 'Manage user saved views'],
            ['key' => 'views.set_default', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'default', 'label' => 'Set default saved view'],
            ['key' => 'views.lock_configuration', 'module' => 'Saved Views', 'screen' => 'Views', 'category' => 'view', 'action' => 'lock', 'label' => 'Lock saved view configuration'],
        ];

        $routes = [
            ['grn.dashboard.view', 'GRN', 'Dashboard', 'page', 'view', 'View GRN dashboard', 'grn.dashboard'],
            ['grn.records.view', 'GRN', 'Records', 'page', 'view', 'View GRN records', 'grn.register'],
            ['grn.register.view', 'GRN', 'Register', 'page', 'view', 'View GRN register', 'grn.register'],
            ['grn.stock_balance.view', 'GRN', 'Stock Balance', 'page', 'view', 'View GRN stock balance', 'grn.stock-balance'],
            ['grn.records.create', 'GRN', 'Records', 'action', 'create', 'Create GRN records', 'grn.check'],
            ['grn.records.update', 'GRN', 'Records', 'action', 'update', 'Update GRN records', 'grn.check'],
            ['grn.records.post', 'GRN', 'Records', 'action', 'post', 'Post GRN records', 'grn.register'],
            ['grn.records.export', 'GRN', 'Records', 'action', 'export', 'Export GRN records', 'grn.register'],
            ['grn.register.tab.summary.view', 'GRN', 'Register Summary', 'section', 'view', 'View GRN register summary', null],
            ['grn.register.tab.audit.view', 'GRN', 'Register Audit', 'section', 'view', 'View GRN register audit', null],
            ['grn.field.invoice_amount.view', 'GRN', 'Invoice Amount', 'field', 'view', 'View invoice amount', null],
            ['grn.field.invoice_amount.edit', 'GRN', 'Invoice Amount', 'field', 'edit', 'Edit invoice amount', null],
            ['qc.dashboard.view', 'QC', 'Dashboard', 'page', 'view', 'View QC dashboard', 'qc.dashboard'],
            ['qc.queue.view', 'QC', 'Queue', 'page', 'view', 'View QC queue', 'qc.queue'],
            ['qc.holds.view', 'QC', 'Holds', 'page', 'view', 'View QC holds', 'qc.holds'],
            ['qc.inspection.perform', 'QC', 'Inspection', 'action', 'perform', 'Perform QC inspection', 'qc.queue'],
            ['qc.hold.create', 'QC', 'Holds', 'action', 'create', 'Create QC hold', 'qc.holds'],
            ['qc.hold.release', 'QC', 'Holds', 'action', 'release', 'Release QC hold', 'qc.holds'],
            ['qc.inspection.section.financial_details.view', 'QC', 'Financial Details', 'section', 'view', 'View QC financial details', null],
            ['vendor.records.view', 'Vendor', 'Submissions', 'page', 'view', 'View vendor records', 'vendor.submissions'],
            ['vendor.submission.section.internal_notes.view', 'Vendor', 'Internal Notes', 'section', 'view', 'View internal vendor notes', null],
            ['vendor.field.bank_account.view', 'Vendor', 'Bank Account', 'field', 'view', 'View vendor bank account', null],
            ['vendor.field.bank_account.mask', 'Vendor', 'Bank Account', 'field', 'mask', 'Mask vendor bank account', null],
            ['unloading.records.manage', 'Unloading', 'Records', 'action', 'manage', 'Manage unloading records', 'unloading.dashboard'],
            ['finance.review.view', 'Finance', 'Review', 'page', 'view', 'View finance review', 'finance.review'],
            ['finance.claims.approve', 'Finance', 'Claims', 'action', 'approve', 'Approve finance claims', 'finance.claims'],
            ['finance.claim.field.amount.view', 'Finance', 'Claim Amount', 'field', 'view', 'View claim amount', null],
            ['finance.claim.field.amount.edit', 'Finance', 'Claim Amount', 'field', 'edit', 'Edit claim amount', null],
            ['admin.users.manage', 'Admin', 'Users', 'action', 'manage', 'Manage admin users', 'admin.users'],
            ['master_data.dashboard.view', 'Master Data', 'Dashboard', 'page', 'view', 'View Master Data dashboard', 'tan90.master-data.dashboard'],
            ['master_data.items.view', 'Master Data', 'Items', 'page', 'view', 'View items', 'tan90.master-data.index'],
            ['master_data.items.approve', 'Master Data', 'Items', 'action', 'approve', 'Approve items', 'tan90.master-data.approve'],
            ['master_data.vendor.tab.bank_details.view', 'Master Data', 'Vendor Bank Details', 'field', 'view', 'View vendor bank details', null],
            ['brc.dashboard.view', 'BOM Recipe Costing', 'Dashboard', 'page', 'view', 'View BRC dashboard', 'tan90.brc.dashboard'],
            ['brc.boms.view', 'BOM Recipe Costing', 'BOMs', 'page', 'view', 'View BOMs', 'tan90.brc.boms.index'],
            ['brc.boms.submit', 'BOM Recipe Costing', 'BOMs', 'action', 'submit', 'Submit BOMs', 'tan90.brc.bom-versions.gates.pass'],
            ['brc.costing.approve_standard', 'BOM Recipe Costing', 'Costing', 'action', 'approve', 'Approve standard costing', 'tan90.brc.costing.approve-standard'],
            ['workspace.view', 'Workspace', 'Dashboard', 'dashboard', 'view', 'View workspace', 'workspace.index'],
            ['workspace.customise', 'Workspace', 'Builder', 'dashboard', 'manage', 'Customise personal workspace', 'workspace.customise'],
            ['dashboard.builder.personal', 'Workspace', 'Builder', 'dashboard', 'personal', 'Use personal dashboard builder', 'workspace.customise'],
            ['dashboard.builder.role', 'Workspace', 'Builder', 'dashboard', 'role', 'Build role dashboard templates', null],
            ['dashboard.builder.team', 'Workspace', 'Builder', 'dashboard', 'team', 'Build team dashboard templates', null],
            ['dashboard.builder.user', 'Workspace', 'Builder', 'dashboard', 'user', 'Build user dashboard templates', null],
            ['dashboard.widget.add', 'Workspace', 'Builder', 'dashboard', 'add', 'Add widgets', null],
            ['dashboard.widget.remove', 'Workspace', 'Builder', 'dashboard', 'remove', 'Remove widgets', null],
            ['dashboard.widget.move', 'Workspace', 'Builder', 'dashboard', 'move', 'Move widgets', null],
            ['dashboard.widget.resize', 'Workspace', 'Builder', 'dashboard', 'resize', 'Resize widgets', null],
            ['dashboard.template.publish', 'Workspace', 'Builder', 'dashboard', 'publish', 'Publish dashboard template', null],
            ['dashboard.widget.lock', 'Workspace', 'Builder', 'dashboard', 'lock', 'Lock widget position/size/config', null],
        ];

        foreach ($routes as [$key, $module, $screen, $category, $action, $label, $route]) {
            $base[] = compact('key', 'module', 'screen', 'category', 'action', 'label') + ['route_name' => $route];
        }

        return array_map(fn ($p) => array_merge($p, [
            'screen' => $p['screen'] ?? $p['module'],
            'category' => $p['category'] ?? 'action',
            'action' => $p['action'] ?? null,
            'description' => $p['description'] ?? null,
            'route_name' => $p['route_name'] ?? null,
            'ui_key' => $p['ui_key'] ?? null,
            'field_key' => $p['field_key'] ?? null,
            'widget_key' => $p['widget_key'] ?? null,
            'allowed_scope_types' => $p['allowed_scope_types'] ?? AccessControlService::SCOPES,
            'sort_order' => $p['sort_order'] ?? 100,
            'is_sensitive' => $p['is_sensitive'] ?? in_array($p['category'] ?? '', ['field', 'access_admin'], true),
            'status' => 'active',
        ]), $base);
    }

    public function widgets(): array
    {
        return [
            ['key' => 'grn_open_records', 'module' => 'GRN', 'title' => 'Open GRN Records', 'description' => 'Counts live GRN rows waiting in the register.', 'permission_key' => 'grn.register.view', 'provider_class' => 'App\\Services\\Access\\Widgets\\GrnRecordsWidget'],
            ['key' => 'qc_queue', 'module' => 'QC', 'title' => 'QC Queue', 'description' => 'Counts QC checks and quality hold data.', 'permission_key' => 'qc.queue.view', 'provider_class' => 'App\\Services\\Access\\Widgets\\QcQueueWidget'],
            ['key' => 'finance_claims', 'module' => 'Finance', 'title' => 'Finance Claims', 'description' => 'Summarises payable finance records.', 'permission_key' => 'finance.review.view', 'provider_class' => 'App\\Services\\Access\\Widgets\\FinanceClaimsWidget'],
            ['key' => 'master_vendors', 'module' => 'Master Data', 'title' => 'Master Vendors', 'description' => 'Counts configured Tan90 vendors.', 'permission_key' => 'master_data.dashboard.view', 'provider_class' => 'App\\Services\\Access\\Widgets\\MasterVendorWidget'],
            ['key' => 'brc_recipes', 'module' => 'BOM Recipe Costing', 'title' => 'Recipes and BOMs', 'description' => 'Counts real BRC recipes and BOMs.', 'permission_key' => 'brc.dashboard.view', 'provider_class' => 'App\\Services\\Access\\Widgets\\BrcRecipeWidget'],
        ];
    }
}
