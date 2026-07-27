<?php

namespace Database\Seeders\Access;

use App\Enums\Role as LegacyRole;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessSavedView;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUnit;
use App\Models\Access\AccessVertical;
use App\Models\Access\DashboardTemplate;
use App\Models\Access\DashboardTemplateItem;
use App\Models\Access\DashboardWidget;
use App\Models\Access\DashboardWidgetCatalog;
use App\Models\User;
use App\Services\Access\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(PermissionRegistry::class);
        $permissions = collect($registry->permissions())->mapWithKeys(function (array $permission) {
            $model = AccessPermission::updateOrCreate(['key' => $permission['key']], $permission);

            return [$model->key => $model];
        });

        foreach ($registry->widgets() as $widget) {
            DashboardWidget::updateOrCreate(['key' => $widget['key']], $widget + ['default_w' => 4, 'default_h' => 3, 'enabled' => true]);
            DashboardWidgetCatalog::updateOrCreate(['key' => $widget['key']], $widget + [
                'supported_variants_json' => ['KPI', 'list', 'chart', 'progress', 'queue'],
                'settings_schema_json' => [],
                'default_w' => 4,
                'default_h' => 3,
                'status' => 'active',
            ]);
        }

        $verticals = [
            'STORE' => AccessVertical::updateOrCreate(['code' => 'STORE'], ['name' => 'Store Operations', 'description' => 'GRN, unloading and warehouse operations', 'status' => 'active']),
            'QUALITY' => AccessVertical::updateOrCreate(['code' => 'QUALITY'], ['name' => 'Quality', 'description' => 'QC inspections, holds and release workflows', 'status' => 'active']),
            'PROCUREMENT' => AccessVertical::updateOrCreate(['code' => 'PROCUREMENT'], ['name' => 'Procurement', 'description' => 'Vendor submissions and inbound procurement coordination', 'status' => 'active']),
            'FINANCE' => AccessVertical::updateOrCreate(['code' => 'FINANCE'], ['name' => 'Finance', 'description' => 'Claims, ledgers and finance approvals', 'status' => 'active']),
            'MASTER-DATA' => AccessVertical::updateOrCreate(['code' => 'MASTER-DATA'], ['name' => 'Master Data', 'description' => 'Master data and BOM/Recipe/Costing administration', 'status' => 'active']),
        ];

        $units = [
            'STORE-BHIWANDI' => AccessUnit::updateOrCreate(['vertical_id' => $verticals['STORE']->id, 'code' => 'BHIWANDI'], ['name' => 'Bhiwandi Warehouse', 'description' => 'Primary inbound operations unit', 'status' => 'active']),
            'QUALITY-BHIWANDI' => AccessUnit::updateOrCreate(['vertical_id' => $verticals['QUALITY']->id, 'code' => 'QC-LAB'], ['name' => 'Bhiwandi QC Lab', 'description' => 'Quality inspection and hold desk', 'status' => 'active']),
            'PROC-VENDOR' => AccessUnit::updateOrCreate(['vertical_id' => $verticals['PROCUREMENT']->id, 'code' => 'VENDOR-DESK'], ['name' => 'Vendor Desk', 'description' => 'Vendor submission and RFQ unit', 'status' => 'active']),
        ];

        $teams = [
            'GRN' => AccessTeam::updateOrCreate(['vertical_id' => $verticals['STORE']->id, 'code' => 'GRN'], ['unit_id' => $units['STORE-BHIWANDI']->id, 'name' => 'GRN Control Team', 'status' => 'active']),
            'QC' => AccessTeam::updateOrCreate(['vertical_id' => $verticals['QUALITY']->id, 'code' => 'QC'], ['unit_id' => $units['QUALITY-BHIWANDI']->id, 'name' => 'QC Inspection Team', 'status' => 'active']),
            'VENDOR' => AccessTeam::updateOrCreate(['vertical_id' => $verticals['PROCUREMENT']->id, 'code' => 'VENDOR'], ['unit_id' => $units['PROC-VENDOR']->id, 'name' => 'Vendor Coordination Team', 'status' => 'active']),
            'FINANCE' => AccessTeam::updateOrCreate(['vertical_id' => $verticals['FINANCE']->id, 'code' => 'FINANCE'], ['unit_id' => null, 'name' => 'Finance Review Team', 'status' => 'active']),
            'MASTERDATA' => AccessTeam::updateOrCreate(['vertical_id' => $verticals['MASTER-DATA']->id, 'code' => 'MASTERDATA'], ['unit_id' => null, 'name' => 'Master Data Team', 'status' => 'active']),
        ];

        $roles = $this->seedRoles($verticals);
        $this->grantRoles($roles, $permissions);
        $users = $this->seedUsers($roles, $verticals, $units, $teams, $permissions);
        $this->seedSavedViews($users, $roles, $teams);
        $this->seedDashboards($users, $roles, $teams);
        $this->seedReferenceRoles($verticals);
    }

    private function seedRoles(array $verticals): array
    {
        $super = AccessRole::updateOrCreate(['code' => 'ACCESS-SUPER-ADMIN'], [
            'name' => 'Super Admin', 'label' => 'Super Admin', 'level' => 1, 'hierarchy_level' => 1,
            'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            'description' => 'All verticals, users, roles, permissions and dashboard defaults.',
        ]);

        $roles = ['SUPER' => $super];
        foreach ([
            'STORE' => ['Store Head', 'Store Vertical Head'],
            'QUALITY' => ['Quality Head', 'Quality Vertical Head'],
            'PROCUREMENT' => ['Procurement Head', 'Procurement Vertical Head'],
            'FINANCE' => ['Finance Head', 'Finance Vertical Head'],
            'MASTER-DATA' => ['Master Data Head', 'Master Data Vertical Head'],
        ] as $code => [$name, $label]) {
            $roles["HEAD-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-$code-HEAD"], [
                'name' => $name, 'label' => $label, 'level' => 2, 'hierarchy_level' => 2,
                'vertical_id' => $verticals[$code]->id, 'parent_role_id' => $super->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        $managerHeadCode = [
            'GRN' => 'STORE', 'QC' => 'QUALITY', 'VENDOR' => 'PROCUREMENT',
            'FINANCE' => 'FINANCE', 'MASTERDATA' => 'MASTER-DATA',
        ];
        foreach ([
            'GRN' => ['Store GRN Manager', $verticals['STORE']],
            'QC' => ['QC Manager', $verticals['QUALITY']],
            'VENDOR' => ['Vendor Manager', $verticals['PROCUREMENT']],
            'FINANCE' => ['Finance Manager', $verticals['FINANCE']],
            'MASTERDATA' => ['Master Data Manager', $verticals['MASTER-DATA']],
        ] as $code => [$name, $vertical]) {
            $roles["MANAGER-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-$code-MANAGER"], [
                'name' => $name, 'label' => 'Manager', 'level' => 3, 'hierarchy_level' => 3,
                'vertical_id' => $vertical->id, 'parent_role_id' => $roles['HEAD-'.$managerHeadCode[$code]]->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        foreach ([
            'GRN' => ['GRN Executive', $verticals['STORE'], $roles['MANAGER-GRN']],
            'QC' => ['QC Executive', $verticals['QUALITY'], $roles['MANAGER-QC']],
            'GRNQC' => ['GRN + QC Executive', $verticals['STORE'], $roles['MANAGER-GRN']],
            'VENDOR' => ['Vendor Executive', $verticals['PROCUREMENT'], $roles['MANAGER-VENDOR']],
            'READONLY' => ['Read-only Executive', $verticals['STORE'], $roles['MANAGER-GRN']],
            'FINANCE' => ['Finance Executive', $verticals['FINANCE'], $roles['MANAGER-FINANCE']],
            'MASTERDATA' => ['Master Data Executive', $verticals['MASTER-DATA'], $roles['MANAGER-MASTERDATA']],
        ] as $code => [$name, $vertical, $parent]) {
            $roles["EXEC-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-$code-EXEC"], [
                'name' => $name, 'label' => 'Executive/Employee', 'level' => 4, 'hierarchy_level' => 4,
                'vertical_id' => $vertical->id, 'parent_role_id' => $parent->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        return $roles;
    }

    private function grantRoles(array $roles, $permissions): void
    {
        $sync = function (AccessRole $role, array $keys, string $scope, bool $delegable = false, string $effect = 'allow') use ($permissions) {
            $payload = [];
            foreach ($keys as $key) {
                if ($permissions->has($key)) {
                    $payload[$permissions[$key]->id] = ['effect' => $effect, 'allowed' => $effect === 'allow', 'delegable' => $delegable, 'scope_type' => $scope, 'max_scope_type' => $scope, 'field_mode' => null, 'locked' => false];
                }
            }
            $role->permissions()->syncWithoutDetaching($payload);
        };

        $allKeys = $permissions->keys()->all();
        $sync($roles['SUPER'], $allKeys, 'all', true);
        foreach (['HEAD-STORE', 'HEAD-QUALITY', 'HEAD-PROCUREMENT', 'HEAD-FINANCE', 'HEAD-MASTER-DATA'] as $key) {
            $sync($roles[$key], $allKeys, 'vertical', true);
        }
        $sync($roles['MANAGER-GRN'], ['workspace.view', 'workspace.customise', 'dashboard.builder.personal', 'dashboard.builder.user', 'dashboard.widget.add', 'dashboard.widget.move', 'dashboard.widget.resize', 'grn.module.access', 'grn.dashboard.view', 'grn.records.view', 'grn.register.view', 'grn.stock_balance.view', 'grn.records.create', 'grn.records.update', 'grn.records.post', 'grn.records.export', 'views.use_assigned', 'views.manage_user'], 'team', true);
        $sync($roles['MANAGER-QC'], ['workspace.view', 'workspace.customise', 'dashboard.builder.personal', 'dashboard.builder.user', 'dashboard.widget.add', 'dashboard.widget.move', 'dashboard.widget.resize', 'qc.module.access', 'qc.dashboard.view', 'qc.queue.view', 'qc.holds.view', 'qc.inspection.perform', 'qc.hold.create', 'qc.hold.release', 'views.use_assigned', 'views.manage_user'], 'team', true);
        $sync($roles['MANAGER-VENDOR'], ['workspace.view', 'workspace.customise', 'vendor.module.access', 'vendor.records.view', 'views.use_assigned', 'views.manage_user'], 'team', true);
        $sync($roles['EXEC-GRN'], ['workspace.view', 'dashboard.builder.personal', 'grn.module.access', 'grn.dashboard.view', 'grn.records.view', 'grn.register.view', 'grn.stock_balance.view', 'views.use_assigned'], 'assigned');
        $sync($roles['EXEC-QC'], ['workspace.view', 'dashboard.builder.personal', 'qc.module.access', 'qc.dashboard.view', 'qc.queue.view', 'qc.holds.view', 'views.use_assigned'], 'assigned');
        $sync($roles['EXEC-GRNQC'], ['workspace.view', 'dashboard.builder.personal', 'grn.module.access', 'grn.dashboard.view', 'grn.records.view', 'grn.register.view', 'qc.module.access', 'qc.dashboard.view', 'qc.queue.view', 'views.use_assigned'], 'assigned');
        $sync($roles['EXEC-VENDOR'], ['workspace.view', 'vendor.module.access', 'vendor.records.view', 'views.use_assigned'], 'self');
        $sync($roles['EXEC-READONLY'], ['workspace.view', 'grn.module.access', 'grn.dashboard.view', 'grn.records.view', 'grn.register.view', 'views.use_assigned'], 'assigned');
        $sync($roles['MANAGER-FINANCE'], ['workspace.view', 'workspace.customise', 'dashboard.builder.personal', 'dashboard.builder.user', 'dashboard.widget.add', 'dashboard.widget.move', 'dashboard.widget.resize', 'finance.module.access', 'finance.review.view', 'finance.claims.approve', 'finance.claim.field.amount.view', 'finance.claim.field.amount.edit', 'views.use_assigned', 'views.manage_user'], 'team', true);
        $sync($roles['EXEC-FINANCE'], ['workspace.view', 'dashboard.builder.personal', 'finance.module.access', 'finance.review.view', 'finance.claim.field.amount.view', 'views.use_assigned'], 'assigned');
        $sync($roles['MANAGER-MASTERDATA'], ['workspace.view', 'workspace.customise', 'dashboard.builder.personal', 'dashboard.builder.user', 'dashboard.widget.add', 'dashboard.widget.move', 'dashboard.widget.resize', 'master_data.module.access', 'master_data.dashboard.view', 'master_data.items.view', 'master_data.items.approve', 'master_data.vendor.tab.bank_details.view', 'views.use_assigned', 'views.manage_user'], 'team', true);
        $sync($roles['EXEC-MASTERDATA'], ['workspace.view', 'dashboard.builder.personal', 'master_data.module.access', 'master_data.dashboard.view', 'master_data.items.view', 'views.use_assigned'], 'assigned');
    }

    private function seedUsers(array $roles, array $verticals, array $units, array $teams, $permissions): array
    {
        $specs = [
            'superadmin@tan90.demo' => ['Ananya Rao', $roles['SUPER'], 1, null, null, null, null, null],
            'head.store@tan90.demo' => ['Kavita Menon', $roles['HEAD-STORE'], 2, $verticals['STORE'], null, null, null, null],
            'head.quality@tan90.demo' => ['Irfan Sheikh', $roles['HEAD-QUALITY'], 2, $verticals['QUALITY'], null, null, null, null],
            'head.procurement@tan90.demo' => ['Meera Nair', $roles['HEAD-PROCUREMENT'], 2, $verticals['PROCUREMENT'], null, null, null, null],
            'manager.grn@tan90.demo' => ['Dev Patil', $roles['MANAGER-GRN'], 3, $verticals['STORE'], $units['STORE-BHIWANDI'], $teams['GRN'], 'head.store@tan90.demo', LegacyRole::StoreManager],
            'manager.qc@tan90.demo' => ['Nisha Kulkarni', $roles['MANAGER-QC'], 3, $verticals['QUALITY'], $units['QUALITY-BHIWANDI'], $teams['QC'], 'head.quality@tan90.demo', LegacyRole::Qc],
            'manager.vendor@tan90.demo' => ['Rohit Dsouza', $roles['MANAGER-VENDOR'], 3, $verticals['PROCUREMENT'], $units['PROC-VENDOR'], $teams['VENDOR'], 'head.procurement@tan90.demo', LegacyRole::Vendor],
            'executive.grn@tan90.demo' => ['Sameer Joshi', $roles['EXEC-GRN'], 4, $verticals['STORE'], $units['STORE-BHIWANDI'], $teams['GRN'], 'manager.grn@tan90.demo', LegacyRole::StoreManager],
            'executive.qc@tan90.demo' => ['Pooja Shah', $roles['EXEC-QC'], 4, $verticals['QUALITY'], $units['QUALITY-BHIWANDI'], $teams['QC'], 'manager.qc@tan90.demo', LegacyRole::Qc],
            'executive.grnqc@tan90.demo' => ['Amit Varma', $roles['EXEC-GRNQC'], 4, $verticals['STORE'], $units['STORE-BHIWANDI'], $teams['GRN'], 'manager.grn@tan90.demo', null],
            'executive.vendor@tan90.demo' => ['Farah Ansari', $roles['EXEC-VENDOR'], 4, $verticals['PROCUREMENT'], $units['PROC-VENDOR'], $teams['VENDOR'], 'manager.vendor@tan90.demo', LegacyRole::Vendor],
            'executive.readonly@tan90.demo' => ['Tara Iyer', $roles['EXEC-READONLY'], 4, $verticals['STORE'], $units['STORE-BHIWANDI'], $teams['GRN'], 'manager.grn@tan90.demo', null],
            'head.finance@tan90.demo' => ['Sanjay Kapoor', $roles['HEAD-FINANCE'], 2, $verticals['FINANCE'], null, null, null, null],
            'head.masterdata@tan90.demo' => ['Priya Nambiar', $roles['HEAD-MASTER-DATA'], 2, $verticals['MASTER-DATA'], null, null, null, null],
            'manager.finance@tan90.demo' => ['Ashok Verma', $roles['MANAGER-FINANCE'], 3, $verticals['FINANCE'], null, $teams['FINANCE'], 'head.finance@tan90.demo', LegacyRole::Finance],
            'manager.masterdata@tan90.demo' => ['Divya Reddy', $roles['MANAGER-MASTERDATA'], 3, $verticals['MASTER-DATA'], null, $teams['MASTERDATA'], 'head.masterdata@tan90.demo', null],
            'executive.finance@tan90.demo' => ['Rakesh Bhatt', $roles['EXEC-FINANCE'], 4, $verticals['FINANCE'], null, $teams['FINANCE'], 'manager.finance@tan90.demo', LegacyRole::Finance],
            'executive.masterdata@tan90.demo' => ['Neha Choudhary', $roles['EXEC-MASTERDATA'], 4, $verticals['MASTER-DATA'], null, $teams['MASTERDATA'], 'manager.masterdata@tan90.demo', null],
        ];

        $users = [];
        foreach ($specs as $email => [$name, $role, $level, $vertical, $unit, $team, $managerEmail, $legacyRole]) {
            $users[$email] = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'phone' => '+91 90000 '.str_pad((string) count($users), 5, '0', STR_PAD_LEFT),
                'role' => $legacyRole,
                'access_mode' => 'advanced',
                'password' => Hash::make('demo123'),
                'is_active' => true,
                'super_admin' => $level === 1,
            ]);
        }

        foreach ($specs as $email => [, $role, $level, $vertical, $unit, $team, $managerEmail]) {
            $user = $users[$email];
            $manager = $managerEmail ? $users[$managerEmail] : null;
            AccessPosition::updateOrCreate(['user_id' => $user->id, 'is_primary' => true], [
                'hierarchy_level' => $level, 'vertical_id' => $vertical?->id, 'unit_id' => $unit?->id, 'team_id' => $team?->id,
                'reports_to_user_id' => $manager?->id, 'starts_at' => now(), 'status' => 'active',
            ]);
            DB::table('access_user_roles')->updateOrInsert(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('access_user_role_assignments')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id, 'vertical_id' => $vertical?->id, 'unit_id' => $unit?->id, 'team_id' => $team?->id],
                ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'is_primary' => true, 'reason' => 'Seeded Tan90 demo hierarchy', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $teams['GRN']->update(['manager_user_id' => $users['manager.grn@tan90.demo']->id]);
        $teams['QC']->update(['manager_user_id' => $users['manager.qc@tan90.demo']->id]);
        $teams['VENDOR']->update(['manager_user_id' => $users['manager.vendor@tan90.demo']->id]);
        $teams['FINANCE']->update(['manager_user_id' => $users['manager.finance@tan90.demo']->id]);
        $teams['MASTERDATA']->update(['manager_user_id' => $users['manager.masterdata@tan90.demo']->id]);

        $this->override($users['executive.grnqc@tan90.demo'], $users['manager.grn@tan90.demo'], $permissions['qc.queue.view'], 'allow', 'assigned', 'Cross-functional GRN+QC direct grant');
        $this->override($users['executive.readonly@tan90.demo'], $users['manager.grn@tan90.demo'], $permissions['grn.records.update'], 'deny', 'assigned', 'Read-only demo restriction');
        $this->override($users['executive.grn@tan90.demo'], $users['manager.grn@tan90.demo'], $permissions['grn.field.invoice_amount.view'], 'allow', 'assigned', 'Temporary invoice amount visibility', now()->addDays(7));

        return $users;
    }

    private function override(User $target, User $actor, AccessPermission $permission, string $effect, string $scope, string $reason, $expiresAt = null): void
    {
        DB::table('access_user_permission_overrides')->updateOrInsert(
            ['user_id' => $target->id, 'permission_id' => $permission->id],
            ['granted_by' => $actor->id, 'allowed' => $effect === 'allow', 'effect' => $effect, 'scope_type' => $scope, 'reason' => $reason, 'starts_at' => now(), 'expires_at' => $expiresAt, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function seedSavedViews(array $users, array $roles, array $teams): void
    {
        $view = AccessSavedView::updateOrCreate(['key' => 'grn-qc-exec-focused'], [
            'uuid' => (string) Str::uuid(), 'module' => 'GRN', 'screen_key' => 'grn.register', 'name' => 'GRN + QC Focus View',
            'description' => 'Scoped list view for the cross-functional executive demo.',
            'owner_user_id' => $users['manager.grn@tan90.demo']->id, 'owner_level' => 3,
            'columns_json' => ['gate_no', 'po_number', 'vendor_name', 'status', 'invoice_amount'],
            'filters_json' => ['status' => ['pending_validation', 'closed']], 'sort_json' => ['created_at' => 'desc'],
            'display_json' => ['density' => 'compact'], 'row_actions_json' => ['view'], 'locked_parts_json' => ['columns' => ['invoice_amount']],
            'status' => 'published', 'version' => 1,
        ]);

        foreach ([[$roles['EXEC-GRNQC'], 'role'], [$teams['GRN'], 'team'], [$users['executive.grnqc@tan90.demo'], 'user']] as [$assignable, $type]) {
            DB::table('access_saved_view_assignments')->updateOrInsert(
                ['saved_view_id' => $view->id, 'assignable_type' => $type, 'assignable_id' => $assignable->id],
                ['is_default' => $type === 'user', 'can_personalise' => $type !== 'role', 'assigned_by' => $users['manager.grn@tan90.demo']->id, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function seedDashboards(array $users, array $roles, array $teams): void
    {
        $template = DashboardTemplate::updateOrCreate(['name' => 'GRN + QC Combined Dashboard', 'owner_type' => 'user', 'owner_id' => $users['executive.grnqc@tan90.demo']->id], [
            'uuid' => (string) Str::uuid(), 'status' => 'published', 'version' => 1,
            'created_by' => $users['manager.grn@tan90.demo']->id, 'published_by' => $users['manager.grn@tan90.demo']->id, 'published_at' => now(),
            'responsive_layouts_json' => ['desktop' => 12, 'tablet' => 8, 'mobile' => 1],
        ]);
        foreach ([['grn_open_records', 0], ['qc_queue', 4]] as [$widget, $x]) {
            DashboardTemplateItem::updateOrCreate(['template_id' => $template->id, 'widget_key' => $widget, 'page_key' => 'main'], [
                'x' => $x, 'y' => 0, 'w' => 4, 'h' => 3, 'visible' => true, 'mandatory' => true, 'position_locked' => false, 'size_locked' => false, 'config_locked' => false, 'sort_order' => $x,
            ]);
        }
        DB::table('dashboard_assignments')->updateOrInsert(['template_id' => $template->id, 'assignable_type' => 'user', 'assignable_id' => $users['executive.grnqc@tan90.demo']->id], ['priority' => 100, 'assigned_by' => $users['manager.grn@tan90.demo']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedReferenceRoles(array $verticals): void
    {
        foreach (LegacyRole::cases() as $role) {
            AccessRole::updateOrCreate(['code' => 'LEGACY-'.strtoupper($role->value)], [
                'name' => $role->label(), 'label' => 'Legacy '.$role->label(), 'level' => $role === LegacyRole::Admin ? 1 : 4,
                'hierarchy_level' => $role === LegacyRole::Admin ? 1 : 4, 'vertical_id' => $role === LegacyRole::Finance ? $verticals['FINANCE']->id : $verticals['STORE']->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active',
                'description' => 'Reference role mirroring users.role without replacing legacy behavior.',
            ]);
        }
    }
}
