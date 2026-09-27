<?php

namespace Database\Seeders;

use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Tan90MasterDataSeeder seeds ROLE-QC/ROLE-FINANCE/ROLE-PROCUREMENT as roles
 * (they're referenced by the seeded approval workflow steps) but never
 * creates a demo user for them - by design, per its own PHASE_2_SCOPE.md,
 * since those roles exist only to make the seeded multi-step workflows
 * completable. That leaves 3 dead-end tiles on the unified login screen
 * (every active tan90_roles row gets a tile - see login.blade.php) that
 * 404 on click, since /tan90-role-login/{code} requires a UserProfile to
 * already exist. This fills that gap so every tile is actually clickable.
 */
class Tan90MasterDataGapRolesSeeder extends Seeder
{
    private const USERS = [
        ['email' => 'qc@tan90.demo', 'name' => 'QC Reviewer', 'role' => 'ROLE-QC', 'employee_id' => 'T90-0301', 'department' => 'Quality'],
        ['email' => 'finance@tan90.demo', 'name' => 'Finance Reviewer', 'role' => 'ROLE-FINANCE', 'employee_id' => 'T90-0401', 'department' => 'Finance'],
        ['email' => 'procurement@tan90.demo', 'name' => 'Procurement Reviewer', 'role' => 'ROLE-PROCUREMENT', 'employee_id' => 'T90-0501', 'department' => 'Procurement'],
    ];

    public function run(): void
    {
        foreach (self::USERS as $seed) {
            $user = User::updateOrCreate(
                ['email' => $seed['email']],
                ['name' => $seed['name'], 'password' => Hash::make('demo123'), 'email_verified_at' => now()]
            );

            $role = Role::where('code', $seed['role'])->first();

            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tan90_role_id' => $role?->id,
                    'employee_id' => $seed['employee_id'],
                    'department' => $seed['department'],
                    'all_locations' => true,
                    'mfa_status' => 'enabled',
                    'status' => 'active',
                ]
            );
        }
    }
}
