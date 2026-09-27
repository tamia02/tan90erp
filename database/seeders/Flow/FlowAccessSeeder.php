<?php

namespace Database\Seeders\Flow;

use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUnit;
use App\Models\Access\AccessVertical;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Seeds Space 06 (Flow) into the same Access Control system Spaces
// 01/02/04/05 already use - a WAREHOUSE-FULFILMENT vertical with Flow's own
// L2-L4 hierarchy (Head of Warehouse & Fulfilment -> Warehouse/Logistics/
// Customer Fulfilment Manager -> FG Store/Picker-Packer/Dispatch/Transport/
// Closure Executive) per ARCHITECTURE.md #7. Must run after AccessControlSeeder
// (permissions must already exist).
class FlowAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = AccessPermission::where('module', 'Flow')->orWhere('key', 'like', 'flow.%')->pluck('id', 'key');

        $vertical = AccessVertical::updateOrCreate(['code' => 'WAREHOUSE-FULFILMENT'], [
            'name' => 'Warehouse & Fulfilment', 'description' => 'Finished-goods warehouse, customer orders and delivery', 'status' => 'active',
        ]);
        $unit = AccessUnit::updateOrCreate(['vertical_id' => $vertical->id, 'code' => 'FG-WH-1'], [
            'name' => 'Bhiwandi FG Warehouse', 'description' => 'Primary finished-goods warehouse', 'status' => 'active',
        ]);
        $team = AccessTeam::updateOrCreate(['vertical_id' => $vertical->id, 'code' => 'FULFILMENT'], [
            'unit_id' => $unit->id, 'name' => 'Fulfilment Team', 'status' => 'active',
        ]);

        $roles = $this->seedRoles($vertical);
        $this->grantRoles($roles, $permissions);
        $users = $this->seedUsers($roles, $vertical, $unit, $team);
        $team->update(['manager_user_id' => $users['manager.warehouse@tan90.demo']->id]);
    }

    private function seedRoles(AccessVertical $vertical): array
    {
        $super = AccessRole::where('code', 'ACCESS-SUPER-ADMIN')->first();

        $head = AccessRole::updateOrCreate(['code' => 'ACCESS-FULFILMENT-HEAD'], [
            'name' => 'Head of Warehouse & Fulfilment', 'label' => 'Head of Warehouse & Fulfilment', 'level' => 2, 'hierarchy_level' => 2,
            'vertical_id' => $vertical->id, 'parent_role_id' => $super?->id,
            'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
        ]);

        $roles = ['HEAD' => $head];
        foreach ([
            'WAREHOUSE' => 'Warehouse Manager',
            'LOGISTICS' => 'Logistics Manager',
            'CUSTOMER' => 'Customer Fulfilment Manager',
        ] as $code => $name) {
            $roles["MANAGER-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-FLOW-$code-MANAGER"], [
                'name' => $name, 'label' => 'Manager', 'level' => 3, 'hierarchy_level' => 3,
                'vertical_id' => $vertical->id, 'parent_role_id' => $head->id,
                'role_kind' => 'system', 'is_system' => true, 'status' => 'active', 'version' => 1,
            ]);
        }

        foreach ([
            'FGSTORE' => ['FG Store Executive', $roles['MANAGER-WAREHOUSE']],
            'PICKPACK' => ['Picker-Packer', $roles['MANAGER-WAREHOUSE']],
            'DISPATCH' => ['Dispatch Executive', $roles['MANAGER-LOGISTICS']],
            'TRANSPORT' => ['Transport Executive', $roles['MANAGER-LOGISTICS']],
            'CLOSURE' => ['Closure Executive', $roles['MANAGER-CUSTOMER']],
        ] as $code => [$name, $parent]) {
            $roles["EXEC-$code"] = AccessRole::updateOrCreate(['code' => "ACCESS-FLOW-$code-EXEC"], [
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

        $allFlowKeys = $permissions->keys()->all();
        $common = ['flow.module.access', 'flow.dashboard.view', 'workspace.view'];

        $sync($roles['HEAD'], $allFlowKeys, 'vertical', true);

        $sync($roles['MANAGER-WAREHOUSE'], array_merge($common, [
            'flow.inventory.view', 'flow.inventory.receive', 'flow.inventory.putaway',
            'flow.order.view', 'flow.wave.manage', 'flow.pick.confirm', 'flow.pack.manage', 'flow.return.manage',
        ]), 'team', true);

        $sync($roles['MANAGER-LOGISTICS'], array_merge($common, [
            'flow.order.view', 'flow.dispatch.manage', 'flow.delivery.manage', 'flow.return.manage',
        ]), 'team', true);

        $sync($roles['MANAGER-CUSTOMER'], array_merge($common, [
            'flow.order.view', 'flow.order.create', 'flow.order.release', 'flow.delivery.manage', 'flow.return.manage',
        ]), 'team', true);

        $sync($roles['EXEC-FGSTORE'], array_merge($common, ['flow.inventory.view', 'flow.inventory.receive', 'flow.inventory.putaway', 'flow.return.manage']), 'assigned');
        $sync($roles['EXEC-PICKPACK'], array_merge($common, ['flow.wave.manage', 'flow.pick.confirm', 'flow.pack.manage']), 'assigned');
        $sync($roles['EXEC-DISPATCH'], array_merge($common, ['flow.dispatch.manage']), 'assigned');
        $sync($roles['EXEC-TRANSPORT'], array_merge($common, ['flow.dispatch.manage', 'flow.delivery.manage']), 'assigned');
        $sync($roles['EXEC-CLOSURE'], array_merge($common, ['flow.delivery.manage', 'flow.return.manage']), 'assigned');
    }

    private function seedUsers(array $roles, AccessVertical $vertical, AccessUnit $unit, AccessTeam $team): array
    {
        $specs = [
            'head.fulfilment@tan90.demo' => ['Naina Kohli', $roles['HEAD'], 2, null],
            'manager.warehouse@tan90.demo' => ['Sanjeev Rao', $roles['MANAGER-WAREHOUSE'], 3, 'head.fulfilment@tan90.demo'],
            'manager.logistics@tan90.demo' => ['Fatima Khan', $roles['MANAGER-LOGISTICS'], 3, 'head.fulfilment@tan90.demo'],
            'manager.customer@tan90.demo' => ['Arvind Menon', $roles['MANAGER-CUSTOMER'], 3, 'head.fulfilment@tan90.demo'],
            'fgstore@tan90.demo' => ['Kiran Bhosale', $roles['EXEC-FGSTORE'], 4, 'manager.warehouse@tan90.demo'],
            'pickpack@tan90.demo' => ['Reshma Naik', $roles['EXEC-PICKPACK'], 4, 'manager.warehouse@tan90.demo'],
            'dispatch@tan90.demo' => ['Yusuf Sheikh', $roles['EXEC-DISPATCH'], 4, 'manager.logistics@tan90.demo'],
            'transport@tan90.demo' => ['Vinod Chauhan', $roles['EXEC-TRANSPORT'], 4, 'manager.logistics@tan90.demo'],
            'closure@tan90.demo' => ['Anjali Desai', $roles['EXEC-CLOSURE'], 4, 'manager.customer@tan90.demo'],
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
                'team_id' => $team->id, 'reports_to_user_id' => $manager?->id,
                'starts_at' => now(), 'status' => 'active',
            ]);

            DB::table('access_user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('access_user_role_assignments')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id, 'vertical_id' => $vertical->id, 'unit_id' => $unit->id, 'team_id' => $team->id],
                ['assigned_by' => $manager?->id, 'starts_at' => now(), 'expires_at' => null, 'status' => 'active', 'is_primary' => true, 'reason' => 'Seeded Flow demo hierarchy', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $users;
    }
}
