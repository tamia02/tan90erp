<?php

namespace Database\Seeders;

use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\BomVersion;
use App\Models\Tan90\BomRecipeCosting\Component;
use App\Models\Tan90\BomRecipeCosting\CostRate;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\RecipeVersion;
use App\Models\Tan90\BomRecipeCosting\Routing;
use App\Models\Tan90\BomRecipeCosting\WorkCenter;
use App\Models\Tan90\MasterData\Permission;
use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\UserProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the Codex prompt's demo scenario: 500gm PCM Pouch - Blue, recipe
 * REC-PCM500-R04, BOM BOM-PCM500-R03, routing RT-PCM-PCH-03, released and
 * MRP-ready, plus one record each in Draft/Technical Review/QA Review/Plant
 * Trial. Also seeds the module's 8 roles into the house-wide tan90_roles
 * table (shared with Master Data — see docs/INSTALL.md) and one demo user
 * per role. Every step uses firstOrCreate/updateOrCreate so re-running this
 * seeder is a no-op against already-seeded data.
 */
class Tan90BomRecipeCostingSeeder extends Seeder
{
    private const ROLES = [
        ['code' => 'ROLE-SUPER', 'name' => 'Super Admin', 'type' => 'System'],
        ['code' => 'ROLE-RND', 'name' => 'R&D Manager', 'type' => 'Operational'],
        ['code' => 'ROLE-FORMULATION', 'name' => 'Formulation Engineer', 'type' => 'Operational'],
        ['code' => 'ROLE-COSTING', 'name' => 'Cost Accountant', 'type' => 'Operational'],
        ['code' => 'ROLE-PRODUCTION-ENG', 'name' => 'Production Engineer', 'type' => 'Operational'],
        ['code' => 'ROLE-QA-APPROVER', 'name' => 'QA Approver', 'type' => 'Operational'],
        ['code' => 'ROLE-PLANT', 'name' => 'Plant Manager', 'type' => 'Operational'],
        ['code' => 'ROLE-AUDIT', 'name' => 'Auditor', 'type' => 'Read Only'],
    ];

    // ROLE-SUPER/ROLE-PLANT/ROLE-AUDIT codes are shared with Master Data's
    // seeder on purpose (one Super Admin, one Auditor make sense across the
    // whole system) — admin@tan90.demo is intentionally the same account
    // Master Data seeds, so this seeder only *updates* that one user's
    // profile (still the same ROLE-SUPER row either way). Plant is kept as a
    // distinct account here (plant-brc@tan90.demo) since a BOM Plant Manager
    // and a Master Data Plant User are commonly different people day to day;
    // rename to share one account if your org wants a single Plant login.
    private const USERS = [
        ['email' => 'admin@tan90.demo', 'name' => 'BRC Super Admin', 'role' => 'ROLE-SUPER'],
        ['email' => 'rd@tan90.demo', 'name' => 'R&D Manager', 'role' => 'ROLE-RND'],
        ['email' => 'formula@tan90.demo', 'name' => 'Formulation Engineer', 'role' => 'ROLE-FORMULATION'],
        ['email' => 'costing@tan90.demo', 'name' => 'Cost Accountant', 'role' => 'ROLE-COSTING'],
        ['email' => 'production@tan90.demo', 'name' => 'Production Engineer', 'role' => 'ROLE-PRODUCTION-ENG'],
        ['email' => 'qa@tan90.demo', 'name' => 'QA Approver', 'role' => 'ROLE-QA-APPROVER'],
        ['email' => 'plant-brc@tan90.demo', 'name' => 'BRC Plant Manager', 'role' => 'ROLE-PLANT'],
        ['email' => 'auditor-brc@tan90.demo', 'name' => 'BRC Auditor', 'role' => 'ROLE-AUDIT'],
    ];

    public function run(): void
    {
        $this->seedRolesAndUsers();
        $this->seedPermissionGrants();

        $finishedGood = $this->seedFinishedGoodAndComponents();
        $workCenter = $this->seedWorkCenterAndRates();
        $routing = $this->seedRouting($finishedGood, $workCenter);
        $recipe = $this->seedRecipe($finishedGood);
        $bom = $this->seedBom($finishedGood);
        $this->seedCostSheet($finishedGood, $bom);

        $this->seedGateStageExamples($finishedGood);
    }

    private function seedRolesAndUsers(): void
    {
        foreach (self::ROLES as $role) {
            Role::updateOrCreate(['code' => $role['code']], [
                'name' => $role['name'],
                'type' => $role['type'],
                'status' => 'active',
            ]);
        }

        foreach (self::USERS as $userSeed) {
            $user = User::updateOrCreate(
                ['email' => $userSeed['email']],
                ['name' => $userSeed['name'], 'password' => Hash::make('demo123'), 'email_verified_at' => now()]
            );

            $role = Role::where('code', $userSeed['role'])->first();

            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tan90_role_id' => $role?->id,
                    'employee_id' => 'BRC-' . strtoupper(substr($userSeed['role'], 5, 4)) . '-' . $user->id,
                    'department' => 'BOM, Recipe & Costing',
                    'mfa_status' => 'disabled',
                    'status' => 'active',
                ]
            );
        }
    }

    /** Grants every seeded BRC role full CRUD+approve+export on this module's capability set (Auditor: view/export only). */
    private function seedPermissionGrants(): void
    {
        $catalog = Permission::CATALOG ?? ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'approve' => 'Approve', 'export' => 'Export', 'settings' => 'Settings'];

        foreach ($catalog as $key => $label) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $label]);

            foreach (self::ROLES as $roleSeed) {
                $role = Role::where('code', $roleSeed['code'])->first();
                if (! $role) {
                    continue;
                }

                $allowed = $roleSeed['code'] === 'ROLE-AUDIT' ? in_array($key, ['view', 'export'], true) : true;
                $role->permissions()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
            }
        }
    }

    private function seedFinishedGoodAndComponents(): FinishedGood
    {
        $finishedGood = FinishedGood::updateOrCreate(
            ['code' => 'FG-PCM500-BLUE'],
            [
                'name' => '500gm PCM Pouch - Blue',
                'category' => 'Phase Change Material',
                'uom' => 'EA',
                'pack_size' => '500gm',
                'status' => 'active',
                'approval_status' => 'approved',
            ]
        );

        foreach ([
            ['code' => 'CMP-MGNO3', 'name' => 'Magnesium Nitrate', 'type' => 'Raw Material', 'uom' => 'KG', 'standard_cost' => 42.50],
            ['code' => 'CMP-KCL', 'name' => 'Potassium Chloride', 'type' => 'Raw Material', 'uom' => 'KG', 'standard_cost' => 18.75],
            ['code' => 'CMP-NAF', 'name' => 'Sodium Fluoride', 'type' => 'Raw Material', 'uom' => 'KG', 'standard_cost' => 27.00],
            ['code' => 'CMP-STAB', 'name' => 'Stabilizer', 'type' => 'Raw Material', 'uom' => 'KG', 'standard_cost' => 65.00],
            ['code' => 'CMP-WATER', 'name' => 'Process Water', 'type' => 'Consumable', 'uom' => 'L', 'standard_cost' => 0.05],
            ['code' => 'CMP-POUCH', 'name' => 'PCM Pouch Film - Blue', 'type' => 'Packaging', 'uom' => 'EA', 'standard_cost' => 3.20],
        ] as $component) {
            Component::updateOrCreate(['code' => $component['code']], [...$component, 'status' => 'active', 'approval_status' => 'approved']);
        }

        return $finishedGood;
    }

    private function seedWorkCenterAndRates(): WorkCenter
    {
        $workCenter = WorkCenter::updateOrCreate(
            ['code' => 'WC-PCM-PCH'],
            [
                'name' => 'PCM Pouch Line',
                'plant' => 'Plant 1',
                'capacity_per_hour' => 600,
                'labor_rate' => 220,
                'machine_rate' => 340,
                'overhead_rate' => 95,
                'status' => 'active',
                'approval_status' => 'approved',
            ]
        );

        CostRate::updateOrCreate(['code' => 'CR-UTILITY-STD'], [
            'rate_type' => 'utility',
            'rate_name' => 'Standard Utility Rate',
            'rate' => 12.5,
            'uom' => 'per hour',
            'effective_from' => now()->subYear()->toDateString(),
            'status' => 'active',
            'approval_status' => 'approved',
        ]);

        return $workCenter;
    }

    private function seedRouting(FinishedGood $finishedGood, WorkCenter $workCenter): Routing
    {
        $routing = Routing::updateOrCreate(
            ['code' => 'RT-PCM-PCH-03'],
            ['tan90_finished_good_id' => $finishedGood->id, 'name' => 'PCM Pouch Filling & Sealing', 'status' => 'active', 'approval_status' => 'approved']
        );

        if ($routing->operations()->count() === 0) {
            $routing->operations()->create([
                'sequence' => 1, 'operation_name' => 'Blend', 'tan90_work_center_id' => $workCenter->id,
                'setup_time_minutes' => 15, 'run_time_minutes' => 40,
            ]);
            $routing->operations()->create([
                'sequence' => 2, 'operation_name' => 'Fill & Seal', 'tan90_work_center_id' => $workCenter->id,
                'setup_time_minutes' => 10, 'run_time_minutes' => 25,
            ]);
        }

        return $routing;
    }

    private function seedRecipe(FinishedGood $finishedGood): Recipe
    {
        $recipe = Recipe::updateOrCreate(
            ['code' => 'RCP-PCM500-BLUE'],
            ['tan90_finished_good_id' => $finishedGood->id, 'name' => 'PCM500 Blue Formula', 'formula_tolerance_percent' => 0.5, 'status' => 'active', 'approval_status' => 'approved']
        );

        $version = RecipeVersion::updateOrCreate(
            ['tan90_recipe_id' => $recipe->id, 'revision_code' => 'R04'],
            ['revision_number' => 4, 'gate_status' => 'mrp_ready', 'is_current' => true, 'effective_from' => now()->subMonths(2)->toDateString(), 'released_at' => now()->subMonths(2), 'status' => 'active', 'approval_status' => 'approved']
        );

        if ($version->lines()->count() === 0) {
            $lines = [
                ['code' => 'CMP-MGNO3', 'percentage' => 38.0],
                ['code' => 'CMP-KCL', 'percentage' => 22.0],
                ['code' => 'CMP-NAF', 'percentage' => 15.0],
                ['code' => 'CMP-STAB', 'percentage' => 5.0],
                ['code' => 'CMP-WATER', 'percentage' => 20.0],
            ];
            foreach ($lines as $i => $line) {
                $version->lines()->create([
                    'tan90_component_id' => Component::where('code', $line['code'])->value('id'),
                    'sequence' => $i + 1,
                    'percentage' => $line['percentage'],
                    'wastage_percent' => 1.0,
                ]);
            }
        }

        return $recipe;
    }

    private function seedBom(FinishedGood $finishedGood): Bom
    {
        $bom = Bom::updateOrCreate(
            ['code' => 'BOM-PCM500-R03'],
            ['tan90_finished_good_id' => $finishedGood->id, 'bom_type' => 'production', 'status' => 'active', 'approval_status' => 'approved']
        );

        $version = BomVersion::updateOrCreate(
            ['tan90_bom_id' => $bom->id, 'revision_code' => 'R03'],
            ['revision_number' => 3, 'gate_status' => 'mrp_ready', 'is_current' => true, 'effective_from' => now()->subMonths(2)->toDateString(), 'released_at' => now()->subMonths(2), 'status' => 'active', 'approval_status' => 'approved']
        );

        if ($version->lines()->count() === 0) {
            $lines = [
                ['code' => 'CMP-MGNO3', 'quantity' => 0.190],
                ['code' => 'CMP-KCL', 'quantity' => 0.110],
                ['code' => 'CMP-NAF', 'quantity' => 0.075],
                ['code' => 'CMP-STAB', 'quantity' => 0.025],
                ['code' => 'CMP-WATER', 'quantity' => 0.100],
                ['code' => 'CMP-POUCH', 'quantity' => 1],
            ];
            foreach ($lines as $i => $line) {
                $version->lines()->create([
                    'line_type' => 'component',
                    'tan90_component_id' => Component::where('code', $line['code'])->value('id'),
                    'sequence' => $i + 1,
                    'quantity' => $line['quantity'],
                    'wastage_percent' => 1.0,
                ]);
            }
        }

        return $bom;
    }

    private function seedCostSheet(FinishedGood $finishedGood, Bom $bom): void
    {
        CostSheet::updateOrCreate(
            ['tan90_finished_good_id' => $finishedGood->id, 'cost_period' => '2026-04'],
            [
                'code' => 'CST-PCM500-2026-04',
                'material_cost' => 32.40,
                'labor_cost' => 4.10,
                'machine_cost' => 6.30,
                'utility_cost' => 1.80,
                'overhead_cost' => 2.20,
                'total_standard_cost' => 46.80,
                'status' => 'active',
                'approval_status' => 'approved',
            ]
        );
    }

    /** One extra finished good + recipe/BOM pair for each of Draft/Technical Review/QA Review/Plant Trial. */
    private function seedGateStageExamples(FinishedGood $referenceGood): void
    {
        $stages = [
            'draft' => 'FG-DEMO-DRAFT',
            'technical_review' => 'FG-DEMO-TECHREV',
            'qa_review' => 'FG-DEMO-QAREV',
            'plant_trial' => 'FG-DEMO-TRIAL',
        ];

        foreach ($stages as $gateStatus => $code) {
            $finishedGood = FinishedGood::updateOrCreate(
                ['code' => $code],
                ['name' => 'Demo Product (' . str_replace('_', ' ', $gateStatus) . ')', 'category' => $referenceGood->category, 'uom' => 'EA', 'status' => 'active', 'approval_status' => 'draft']
            );

            $recipe = Recipe::updateOrCreate(
                ['code' => 'RCP-' . $code],
                ['tan90_finished_good_id' => $finishedGood->id, 'name' => $finishedGood->name . ' Formula', 'status' => 'active', 'approval_status' => 'draft']
            );

            $version = RecipeVersion::updateOrCreate(
                ['tan90_recipe_id' => $recipe->id, 'revision_code' => 'R01'],
                ['revision_number' => 1, 'gate_status' => $gateStatus, 'is_current' => true, 'status' => 'active', 'approval_status' => 'draft']
            );

            if ($version->lines()->count() === 0) {
                $version->lines()->create([
                    'tan90_component_id' => Component::query()->value('id'),
                    'sequence' => 1,
                    'percentage' => 100,
                ]);
            }
        }
    }
}
