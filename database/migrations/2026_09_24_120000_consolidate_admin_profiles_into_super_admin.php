<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tan90Super = Schema::hasTable('tan90_roles') ? DB::table('tan90_roles')->where('code', 'ROLE-SUPER')->first() : null;
            if (Schema::hasTable('tan90_roles')) {
                $aliases = DB::table('tan90_roles')->whereIn('code', ['ROLE-ADMIN', 'admin', 'administrator'])->get();
                if (! $tan90Super && $aliases->isNotEmpty()) {
                    $tan90Super = $aliases->first();
                    DB::table('tan90_roles')->where('id', $tan90Super->id)->update(['code' => 'ROLE-SUPER']);
                }
                if (! $tan90Super) {
                    $id = DB::table('tan90_roles')->insertGetId([
                        'code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'type' => 'System',
                        'data_scope' => 'All Entities', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $tan90Super = DB::table('tan90_roles')->find($id);
                }

                foreach ($aliases->where('id', '!=', $tan90Super->id) as $alias) {
                    $this->mergeTan90Grants((int) $alias->id, (int) $tan90Super->id);
                    DB::table('tan90_user_profiles')->where('tan90_role_id', $alias->id)->update(['tan90_role_id' => $tan90Super->id, 'updated_at' => now()]);
                    DB::table('tan90_roles')->where('id', $alias->id)->delete();
                }

                DB::table('tan90_roles')->where('id', $tan90Super->id)->update([
                    'code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'type' => 'System',
                    'data_scope' => 'All Entities', 'status' => 'active', 'deleted_at' => null, 'updated_at' => now(),
                ]);
            }

            $accessSuper = Schema::hasTable('access_roles') ? DB::table('access_roles')->where('code', 'ACCESS-SUPER-ADMIN')->first() : null;
            if (Schema::hasTable('access_roles')) {
                $aliases = DB::table('access_roles')->whereIn('code', ['LEGACY-ADMIN', 'ROLE-ADMIN', 'ADMINISTRATOR', 'ADMIN'])->get();
                if (! $accessSuper && $aliases->isNotEmpty()) {
                    $accessSuper = $aliases->first();
                    DB::table('access_roles')->where('id', $accessSuper->id)->update(['code' => 'ACCESS-SUPER-ADMIN']);
                }

                if ($accessSuper) {
                    foreach ($aliases->where('id', '!=', $accessSuper->id) as $alias) {
                        $this->mergeAccessRole((int) $alias->id, (int) $accessSuper->id);
                        DB::table('access_roles')->where('parent_role_id', $alias->id)->update(['parent_role_id' => $accessSuper->id]);
                        DB::table('access_roles')->where('id', $alias->id)->delete();
                    }
                    DB::table('access_roles')->where('id', $accessSuper->id)->update([
                        'code' => 'ACCESS-SUPER-ADMIN', 'name' => 'Super Admin', 'label' => 'Super Admin',
                        'level' => 1, 'hierarchy_level' => 1, 'role_kind' => 'system', 'is_system' => true,
                        'status' => 'active', 'description' => 'Unified top-tier administrator with the combined access of all former admin profiles.', 'updated_at' => now(),
                    ]);
                }
            }

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                DB::table('users')->whereRaw('LOWER(role) IN (?, ?, ?)', ['administrator', 'admin', 'superadmin'])->update(['role' => 'admin']);
            }

            if ($tan90Super && Schema::hasTable('users') && Schema::hasTable('tan90_user_profiles')) {
                $adminUsers = DB::table('users')->whereRaw('LOWER(role) = ?', ['admin'])->get(['id']);
                foreach ($adminUsers as $user) {
                    $profile = DB::table('tan90_user_profiles')->where('user_id', $user->id)->first();
                    if ($profile) {
                        DB::table('tan90_user_profiles')->where('id', $profile->id)->update(['tan90_role_id' => $tan90Super->id, 'all_locations' => true, 'status' => 'active', 'updated_at' => now()]);
                    } else {
                        DB::table('tan90_user_profiles')->insert([
                            'user_id' => $user->id, 'tan90_role_id' => $tan90Super->id,
                            'employee_id' => 'SA-'.$user->id, 'department' => 'IT/Admin', 'all_locations' => true,
                            'mfa_status' => 'pending', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            if ($accessSuper && Schema::hasTable('users')) {
                foreach (DB::table('users')->whereRaw('LOWER(role) = ?', ['admin'])->pluck('id') as $userId) {
                    if (Schema::hasTable('access_user_roles')) {
                        DB::table('access_user_roles')->insertOrIgnore([
                            'user_id' => $userId, 'role_id' => $accessSuper->id, 'status' => 'active',
                            'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    if (Schema::hasTable('access_user_role_assignments')) {
                        DB::table('access_user_role_assignments')->insertOrIgnore([
                            'user_id' => $userId, 'role_id' => $accessSuper->id, 'status' => 'active',
                            'is_primary' => true, 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            foreach ([
                'tan90_approval_workflow_steps' => 'step_role',
                'tan90_sla_policies' => 'escalation_role',
                'tan90_brc_approvals' => 'approver_role',
            ] as $table => $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->whereRaw("LOWER({$column}) IN (?, ?, ?)", ['admin', 'administrator', 'super admin'])->update([$column => 'Super Admin']);
                }
            }
        });
    }

    private function mergeTan90Grants(int $sourceId, int $targetId): void
    {
        foreach (DB::table('tan90_role_permission')->where('tan90_role_id', $sourceId)->get() as $grant) {
            $existing = DB::table('tan90_role_permission')->where('tan90_role_id', $targetId)->where('tan90_permission_id', $grant->tan90_permission_id)->first();
            if ($existing) {
                DB::table('tan90_role_permission')->where('id', $existing->id)->update(['allowed' => (bool) $existing->allowed || (bool) $grant->allowed, 'updated_at' => now()]);
                DB::table('tan90_role_permission')->where('id', $grant->id)->delete();
            } else {
                DB::table('tan90_role_permission')->where('id', $grant->id)->update(['tan90_role_id' => $targetId, 'updated_at' => now()]);
            }
        }
    }

    private function mergeAccessRole(int $sourceId, int $targetId): void
    {
        foreach (DB::table('access_role_permissions')->where('role_id', $sourceId)->get() as $grant) {
            $existing = DB::table('access_role_permissions')->where('role_id', $targetId)->where('permission_id', $grant->permission_id)->first();
            if ($existing) {
                $updates = [
                    'allowed' => (bool) $existing->allowed || (bool) $grant->allowed,
                    'delegable' => (bool) ($existing->delegable ?? false) || (bool) ($grant->delegable ?? false),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('access_role_permissions', 'scope_type')) $updates['scope_type'] = 'all';
                if (Schema::hasColumn('access_role_permissions', 'scope_json')) $updates['scope_json'] = null;
                if (Schema::hasColumn('access_role_permissions', 'max_scope_type')) $updates['max_scope_type'] = 'all';
                if (Schema::hasColumn('access_role_permissions', 'effect') && ((bool) $existing->allowed || (bool) $grant->allowed)) $updates['effect'] = 'allow';
                DB::table('access_role_permissions')->where('id', $existing->id)->update($updates);
                DB::table('access_role_permissions')->where('id', $grant->id)->delete();
            } else {
                $values = ['role_id' => $targetId, 'updated_at' => now()];
                if (Schema::hasColumn('access_role_permissions', 'scope_type')) $values['scope_type'] = 'all';
                if (Schema::hasColumn('access_role_permissions', 'max_scope_type')) $values['max_scope_type'] = 'all';
                if (Schema::hasColumn('access_role_permissions', 'effect') && (bool) $grant->allowed) $values['effect'] = 'allow';
                if (Schema::hasColumn('access_role_permissions', 'scope_json')) $values['scope_json'] = null;
                DB::table('access_role_permissions')->where('id', $grant->id)->update($values);
            }
        }

        if (Schema::hasTable('access_user_roles')) {
            foreach (DB::table('access_user_roles')->where('role_id', $sourceId)->get() as $assignment) {
                $existing = DB::table('access_user_roles')->where('user_id', $assignment->user_id)->where('role_id', $targetId)->first();
                if ($existing) {
                    DB::table('access_user_roles')->where('id', $existing->id)->update([
                        'status' => $existing->status === 'active' || $assignment->status === 'active' ? 'active' : $existing->status,
                        'starts_at' => min(array_filter([$existing->starts_at, $assignment->starts_at])) ?: null,
                        'expires_at' => ! $existing->expires_at || ! $assignment->expires_at ? null : max($existing->expires_at, $assignment->expires_at),
                        'updated_at' => now(),
                    ]);
                    DB::table('access_user_roles')->where('id', $assignment->id)->delete();
                } else {
                    DB::table('access_user_roles')->where('id', $assignment->id)->update(['role_id' => $targetId, 'updated_at' => now()]);
                }
            }
        }

        if (Schema::hasTable('access_user_role_assignments')) {
            foreach (DB::table('access_user_role_assignments')->where('role_id', $sourceId)->get() as $assignment) {
                $match = DB::table('access_user_role_assignments')->where('user_id', $assignment->user_id)->where('role_id', $targetId)
                    ->where('vertical_id', $assignment->vertical_id)->where('unit_id', $assignment->unit_id)->where('team_id', $assignment->team_id)->first();
                if ($match) {
                    DB::table('access_user_role_assignments')->where('id', $match->id)->update([
                        'status' => $match->status === 'active' || $assignment->status === 'active' ? 'active' : $match->status,
                        'is_primary' => (bool) $match->is_primary || (bool) $assignment->is_primary,
                        'updated_at' => now(),
                    ]);
                    DB::table('access_user_role_assignments')->where('id', $assignment->id)->delete();
                } else {
                    DB::table('access_user_role_assignments')->where('id', $assignment->id)->update(['role_id' => $targetId, 'updated_at' => now()]);
                }
            }
        }

        if (Schema::hasTable('access_role_dashboard_layouts')) {
            foreach (DB::table('access_role_dashboard_layouts')->where('role_id', $sourceId)->get() as $layout) {
                $existing = DB::table('access_role_dashboard_layouts')->where('role_id', $targetId)->where('widget_key', $layout->widget_key)->first();
                if ($existing) {
                    DB::table('access_role_dashboard_layouts')->where('id', $existing->id)->update([
                        'visible' => (bool) $existing->visible || (bool) $layout->visible,
                        'mandatory' => (bool) $existing->mandatory || (bool) $layout->mandatory,
                        'locked' => (bool) $existing->locked || (bool) $layout->locked,
                        'updated_at' => now(),
                    ]);
                    DB::table('access_role_dashboard_layouts')->where('id', $layout->id)->delete();
                } else {
                    DB::table('access_role_dashboard_layouts')->where('id', $layout->id)->update(['role_id' => $targetId, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Role consolidation is intentionally irreversible: the migration
        // combines assignments and permission grants from retired roles.
    }
};
