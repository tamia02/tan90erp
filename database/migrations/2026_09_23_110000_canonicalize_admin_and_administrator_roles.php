<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('tan90_roles')) {
                $roles = DB::table('tan90_roles')
                    ->whereIn('code', ['admin', 'ROLE-ADMIN'])
                    ->orderByRaw("CASE WHEN code = 'ROLE-ADMIN' THEN 0 ELSE 1 END")
                    ->get();

                $survivor = $roles->first();

                if ($survivor) {
                    foreach ($roles->where('id', '!=', $survivor->id) as $retired) {
                        foreach (DB::table('tan90_role_permission')->where('tan90_role_id', $retired->id)->get() as $grant) {
                            $existing = DB::table('tan90_role_permission')
                                ->where('tan90_role_id', $survivor->id)
                                ->where('tan90_permission_id', $grant->tan90_permission_id)
                                ->first();

                            if ($existing) {
                                DB::table('tan90_role_permission')->where('id', $existing->id)->update([
                                    'allowed' => (bool) $existing->allowed || (bool) $grant->allowed,
                                    'updated_at' => now(),
                                ]);
                            } else {
                                DB::table('tan90_role_permission')->where('id', $grant->id)->update([
                                    'tan90_role_id' => $survivor->id,
                                    'updated_at' => now(),
                                ]);
                            }
                        }

                        DB::table('tan90_user_profiles')->where('tan90_role_id', $retired->id)->update([
                            'tan90_role_id' => $survivor->id,
                            'updated_at' => now(),
                        ]);
                        DB::table('tan90_roles')->where('id', $retired->id)->delete();
                    }

                    DB::table('tan90_roles')->where('id', $survivor->id)->update([
                        'code' => 'ROLE-ADMIN',
                        'name' => 'Admin',
                        'type' => 'System',
                        'data_scope' => 'All Entities',
                        'status' => 'active',
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('tan90_roles')->insert([
                        'code' => 'ROLE-ADMIN',
                        'name' => 'Admin',
                        'type' => 'System',
                        'data_scope' => 'All Entities',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Older builds could persist the display label in users.role.
            // Normalize those records to the enum's stable `admin` value.
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                DB::table('users')->whereRaw('LOWER(role) IN (?, ?)', ['administrator', 'admin'])
                    ->update(['role' => 'admin']);
            }

            // This access-control row is a reference mirror of users.role,
            // not the separate Super Admin role. Keep its ID/code so existing
            // assignments retain their grants and scope, and normalize only
            // the visible role name.
            if (Schema::hasTable('access_roles')) {
                DB::table('access_roles')->where('code', 'LEGACY-ADMIN')->update([
                    'name' => 'Admin',
                    'label' => 'Admin',
                    'updated_at' => now(),
                ]);
            }

            foreach ([
                'tan90_approval_workflow_steps' => 'step_role',
                'tan90_sla_policies' => 'escalation_role',
                'tan90_brc_approvals' => 'approver_role',
            ] as $table => $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->whereRaw("LOWER({$column}) IN (?, ?)", ['administrator', 'admin'])
                        ->update([$column => 'Admin']);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('tan90_roles')) {
                $canonical = DB::table('tan90_roles')->where('code', 'ROLE-ADMIN')->first();
                $legacy = DB::table('tan90_roles')->where('code', 'admin')->first();

                if ($canonical && ! $legacy) {
                    DB::table('tan90_roles')->where('id', $canonical->id)->update([
                        'code' => 'admin',
                        'name' => 'Administrator',
                        'data_scope' => 'Inward to GRN Control Tower',
                        'updated_at' => now(),
                    ]);
                }
            }

            if (Schema::hasTable('access_roles')) {
                DB::table('access_roles')->where('code', 'LEGACY-ADMIN')->update([
                    'name' => 'Administrator',
                    'label' => 'Legacy Administrator',
                    'updated_at' => now(),
                ]);
            }
        });
    }
};
