<?php

namespace Database\Seeders;

use App\Enums\Role as GrnRole;
use App\Models\Tan90\MasterData\Role;
use Illuminate\Database\Seeder;

/**
 * Registers full_project's 7 existing GRN roles (App\Enums\Role) as
 * reference-only rows in the shared tan90_roles table, using the same
 * `code` as the enum's ->value, so every module's roles are listable from
 * one table (used by the unified login screen).
 *
 * This does NOT change how GRN authorization works: EnsureRole middleware,
 * RoleNavigation, NotificationCenter, and the `users.role` column are
 * untouched and remain the source of truth for GRN access. A GRN user gets
 * no tan90_user_profiles row from this seeder — only Master Data/BOM users
 * do (see Tan90MasterDataSeeder / Tan90BomRecipeCostingSeeder). Merging the
 * two into one identity per user is a deliberately deferred follow-up (see
 * the merge plan), not something this seeder attempts.
 */
class Tan90HouseRolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (GrnRole::cases() as $role) {
            Role::updateOrCreate(
                ['code' => $role->value],
                [
                    'name' => $role->label(),
                    'type' => $role === GrnRole::Admin ? 'System' : 'Operational',
                    'data_scope' => 'Inward to GRN Control Tower',
                    'status' => 'active',
                ]
            );
        }
    }
}
