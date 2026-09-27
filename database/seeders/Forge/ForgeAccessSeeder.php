<?php

namespace Database\Seeders\Forge;

use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUnit;
use App\Models\Access\AccessVertical;
use App\Models\Forge\Freezer;
use App\Models\Forge\FreezerReading;
use App\Models\Forge\JobCard;
use App\Models\Forge\Machine;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\BomRecipeCosting\Routing;
use App\Models\Tan90\BomRecipeCosting\WorkCenter;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Seeds Space 05 (Forge) into the same Access Control system Spaces 01/02/04
// already use - a MANUFACTURING vertical alongside STORE/QUALITY/PROCUREMENT/
// FINANCE/MASTER-DATA (see AccessControlSeeder), with Forge's own L2-L4
// hierarchy (Head of Manufacturing -> Production/Quality/Maintenance Manager
// -> Planner/Supervisor/Operator/QC Executive/Maintenance Technician) per
// ARCHITECTURE.md #7. Must run after AccessControlSeeder (permissions must
// already exist) and after Tan90BomRecipeCostingSeeder (needs the released
// FG-PCM500-BLUE line to seed one real work order against).
class ForgeAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = AccessPermission::whereIn('module', ['Forge'])->orWhere('key', 'like', 'forge.%')
            ->pluck('id', 'key');

        $vertical = AccessVertical::updateOrCreate(['code' => 'MANUFACTURING'], [
            'name' => 'Manufacturing', 'description' => 'Production planning, shop-floor execution and in-process/final quality', 'status' => 'active',
        ]);
        $unit = AccessUnit::updateOrCreate(['vertical_id' => $vertical->id, 'code' => 'PLANT-1'], [
            'name' => 'Plant 1', 'description' => 'Primary PCM production plant', 'status' => 'active',
        ]);
        $team = AccessTeam::updateOrCreate(['vertical_id' => $vertical->id, 'code' => 'PRODUCTION'], [
            'unit_id' => $unit->id, 'name' => 'Production Team', 'status' => 'active',
        ]);

        $roles = $this->seedRoles($vertical);
        $this->grantRoles($roles, $permissions);
        $users = $this->seedUsers($roles, $vertical, $unit, $team);
        $team->update(['manager_user_id' => $users['manager.production@tan90.demo']->id]);

        $this->seedDemoData($users);
    }

    private function seedRoles(AccessVertical $vertical): array
    {
        $super = AccessRole::where('code', 'ACCESS-SUPER-ADMIN')->first();

        $head = AccessRole::updateOrCreate(['code' => 'ACCESS-MANUFACTURING-HEAD'], [
            'name' => 'Head of Manufacturing', 'label' => 'Head of Manufacturing', 'level' => 2, 'hierarchy_level' => 2,
            'vertical_id' => $vertical->id, 'parent_role_id' => $super?->id,
            'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
        ]);

        $roles = ['HEAD' => $head];
        foreach ([
            'PRODUCTION' => 'Production Manager',
            'QUALITY' => 'Quality Manager',
            'MAINTENANCE' => 'Maintenance Manager',
        ] as $code => $name) {
            $roles["MANAGER-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-MFG-$code-MANAGER"], [
                'name' => $name, 'label' => 'Manager', 'level' => 3, 'hierarchy_level' => 3,
                'vertical_id' => $vertical->id, 'parent_role_id' => $head->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        foreach ([
            'PLANNER' => ['Planner', $roles['MANAGER-PRODUCTION']],
            'SUPERVISOR' => ['Shift Supervisor', $roles['MANAGER-PRODUCTION']],
            'OPERATOR' => ['Operator', $roles['MANAGER-PRODUCTION']],
            'QC' => ['QC Executive', $roles['MANAGER-QUALITY']],
            'MAINTENANCE' => ['Maintenance Technician', $roles['MANAGER-MAINTENANCE']],
        ] as $code => [$name, $parent]) {
            $roles["EXEC-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-MFG-$code-EXEC"], [
                'name' => $name, 'label' => 'Executive/Employee', 'level' => 4, 'hierarchy_level' => 4,
                'vertical_id' => $vertical->id, 'parent_role_id' => $parent->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        return $roles;
    }

    private function grantRoles(array $roles, $permissions): void
    {
        $sync = function (AccessRole $role, array $keys, string $scope, bool $delegable = false) use ($permissions) {
            $payload = [];
            foreach ($keys as $key) {
                if ($permissions->has($key)) {
                    $payload[$permissions[$key]] = ['effect' => 'allow', 'allowed' => true, 'delegable' => $delegable, 'scope_type' => $scope, 'max_scope_type' => $scope, 'field_mode' => null, 'locked' => false];
                }
            }
            $role->permissions()->syncWithoutDetaching($payload);
        };

        $allForgeKeys = $permissions->keys()->all();
        $common = ['forge.module.access', 'forge.dashboard.view', 'workspace.view'];

        $sync($roles['HEAD'], $allForgeKeys, 'vertical', true);

        $sync($roles['MANAGER-PRODUCTION'], array_merge($common, [
            'forge.plan.view', 'forge.plan.create', 'forge.plan.approve',
            'forge.workorder.view', 'forge.workorder.create', 'forge.workorder.release', 'forge.material.issue', 'forge.jobcard.record',
            'forge.machine.view', 'forge.machine.downtime',
            'forge.freezer.view', 'forge.freezer.monitor',
            'forge.production.record', 'forge.production.approve',
            'forge.wastage.record', 'forge.wastage.approve', 'forge.yield.view',
            'forge.deviation.view', 'forge.deviation.manage', 'forge.batch.trace',
        ]), 'team', true);

        $sync($roles['MANAGER-QUALITY'], array_merge($common, [
            'forge.workorder.view', 'forge.workorder.create', 'forge.ipqc.record', 'forge.ipqc.release',
            'forge.finalqc.record', 'forge.finalqc.release', 'forge.yield.view',
            'forge.deviation.view', 'forge.deviation.manage', 'forge.batch.trace',
        ]), 'team', true);

        $sync($roles['MANAGER-MAINTENANCE'], array_merge($common, [
            'forge.machine.view', 'forge.machine.downtime',
            'forge.freezer.view', 'forge.freezer.monitor',
            'forge.deviation.view', 'forge.deviation.manage',
        ]), 'team', true);

        $sync($roles['EXEC-PLANNER'], array_merge($common, [
            'forge.plan.view', 'forge.plan.create', 'forge.workorder.view', 'forge.workorder.create',
        ]), 'assigned');

        $sync($roles['EXEC-SUPERVISOR'], array_merge($common, [
            'forge.workorder.view', 'forge.material.issue', 'forge.jobcard.record', 'forge.machine.view',
            'forge.freezer.view', 'forge.freezer.monitor',
            'forge.production.record', 'forge.wastage.record', 'forge.deviation.view',
        ]), 'assigned');

        $sync($roles['EXEC-OPERATOR'], array_merge($common, [
            'forge.workorder.view', 'forge.jobcard.record', 'forge.production.record',
        ]), 'assigned');

        $sync($roles['EXEC-QC'], array_merge($common, [
            'forge.workorder.view', 'forge.ipqc.record', 'forge.finalqc.record', 'forge.batch.trace',
        ]), 'assigned');

        $sync($roles['EXEC-MAINTENANCE'], array_merge($common, [
            'forge.machine.view', 'forge.machine.downtime',
            'forge.freezer.view', 'forge.freezer.monitor',
        ]), 'assigned');
    }

    private function seedUsers(array $roles, AccessVertical $vertical, AccessUnit $unit, AccessTeam $team): array
    {
        $specs = [
            'head.manufacturing@tan90.demo' => ['Ravi Kulkarni', $roles['HEAD'], 2, null],
            'manager.production@tan90.demo' => ['Sunita Ghosh', $roles['MANAGER-PRODUCTION'], 3, 'head.manufacturing@tan90.demo'],
            'manager.quality.mfg@tan90.demo' => ['Alok Mishra', $roles['MANAGER-QUALITY'], 3, 'head.manufacturing@tan90.demo'],
            'manager.maintenance@tan90.demo' => ['Geeta Pillai', $roles['MANAGER-MAINTENANCE'], 3, 'head.manufacturing@tan90.demo'],
            'planner@tan90.demo' => ['Rahul Save', $roles['EXEC-PLANNER'], 4, 'manager.production@tan90.demo'],
            'supervisor@tan90.demo' => ['Manoj Thakur', $roles['EXEC-SUPERVISOR'], 4, 'manager.production@tan90.demo'],
            'operator@tan90.demo' => ['Suresh Gaikwad', $roles['EXEC-OPERATOR'], 4, 'manager.production@tan90.demo'],
            'qc.executive.mfg@tan90.demo' => ['Deepa Rane', $roles['EXEC-QC'], 4, 'manager.quality.mfg@tan90.demo'],
            'technician@tan90.demo' => ['Imran Qureshi', $roles['EXEC-MAINTENANCE'], 4, 'manager.maintenance@tan90.demo'],
        ];

        $users = [];
        foreach ($specs as $email => [$name]) {
            $users[$email] = User::updateOrCreate(['email' => $email], [
                'name' => $name, 'password' => Hash::make('demo123'), 'is_active' => true, 'access_mode' => 'advanced',
            ]);
        }

        foreach ($specs as $email => [, $role, $level, $managerEmail]) {
            $user = $users[$email];
            $manager = $managerEmail ? $users[$managerEmail] : null;

            AccessPosition::updateOrCreate(['user_id' => $user->id, 'is_primary' => true], [
                'hierarchy_level' => $level, 'vertical_id' => $vertical->id, 'unit_id' => $unit->id,
                'team_id' => $level >= 3 ? $team->id : $team->id, 'reports_to_user_id' => $manager?->id,
                'starts_at' => now(), 'status' => 'active',
            ]);

            DB::table('access_user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('access_user_role_assignments')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id, 'vertical_id' => $vertical->id, 'unit_id' => $unit->id, 'team_id' => $team->id],
                ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'is_primary' => true, 'reason' => 'Seeded Forge demo hierarchy', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $users;
    }

    private function seedDemoData(array $users): void
    {
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->first();
        $bom = Bom::where('code', 'BOM-PCM500-R03')->first();
        $recipe = Recipe::where('code', 'RCP-PCM500-BLUE')->first();
        $routing = Routing::where('code', 'RT-PCM-PCH-03')->first();
        $workCenter = WorkCenter::where('code', 'WC-PCM-PCH')->first();

        if (! $finishedGood || ! $bom || ! $routing || ! $workCenter) {
            return; // BRC seeder hasn't run yet - nothing to attach a demo work order to.
        }

        $machine = Machine::updateOrCreate(['code' => 'MC-PCM-PCH-01'], [
            'work_center_id' => $workCenter->id, 'name' => 'Pouch Fill & Seal Line 1',
            'plant' => $workCenter->plant, 'state' => 'idle', 'status' => 'active',
        ]);

        $plan = ProductionPlan::updateOrCreate(['plan_number' => 'PP-2026-0001'], [
            'finished_good_id' => $finishedGood->id, 'plant' => $workCenter->plant,
            'target_qty' => 2000, 'uom' => $finishedGood->uom, 'due_date' => now()->addDays(5),
            'status' => 'frozen', 'version' => 1,
            'created_by' => $users['planner@tan90.demo']->id,
            'approved_by' => $users['manager.production@tan90.demo']->id, 'approved_at' => now()->subDay(),
        ]);

        $wo = WorkOrder::updateOrCreate(['wo_number' => 'WO-2026-0001'], [
            'production_plan_id' => $plan->id, 'finished_good_id' => $finishedGood->id,
            'bom_id' => $bom->id, 'recipe_id' => $recipe?->id, 'routing_id' => $routing->id,
            'plant' => $workCenter->plant, 'target_qty' => 500, 'uom' => $finishedGood->uom,
            'status' => 'draft', 'created_by' => $users['planner@tan90.demo']->id,
        ]);

        if ($wo->jobCards()->count() === 0) {
            foreach ($routing->operations as $operation) {
                JobCard::create([
                    'work_order_id' => $wo->id,
                    'routing_operation_id' => $operation->id,
                    'sequence' => $operation->sequence,
                    'operation_name' => $operation->operation_name,
                    'machine_id' => $machine->id,
                    'planned_qty' => $wo->target_qty,
                    'status' => 'pending',
                ]);
            }
        }

        $this->seedFreezers($workCenter->plant);
    }

    private function seedFreezers(?string $plant): void
    {
        $freezerOne = Freezer::updateOrCreate(['code' => 'BF-PCM-01'], [
            'name' => 'Blast Freezer 1', 'plant' => $plant, 'capacity' => 2000,
            'threshold_temp_min' => -25, 'threshold_temp_max' => -18, 'state' => 'idle', 'status' => 'active',
        ]);
        $freezerTwo = Freezer::updateOrCreate(['code' => 'BF-PCM-02'], [
            'name' => 'Blast Freezer 2', 'plant' => $plant, 'capacity' => 1500,
            'threshold_temp_min' => -25, 'threshold_temp_max' => -18, 'state' => 'idle', 'status' => 'active',
        ]);

        // A short, in-range reading history so the dashboard shows a real trend
        // on a fresh install rather than an empty chart.
        if ($freezerOne->readings()->count() === 0) {
            foreach ([-20.1, -19.8, -20.4, -19.6, -20.0] as $i => $temp) {
                FreezerReading::create([
                    'freezer_id' => $freezerOne->id, 'temperature' => $temp, 'humidity' => 62,
                    'is_alert' => false, 'recorded_at' => now()->subHours(5 - $i),
                ]);
            }
        }
        if ($freezerTwo->readings()->count() === 0) {
            foreach ([-21.0, -20.7, -21.2] as $i => $temp) {
                FreezerReading::create([
                    'freezer_id' => $freezerTwo->id, 'temperature' => $temp, 'humidity' => 58,
                    'is_alert' => false, 'recorded_at' => now()->subHours(3 - $i),
                ]);
            }
        }
    }
}
