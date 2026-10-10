<?php

namespace Tests\Feature\AccessControl;

use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PermissionRegistry;
use Database\Seeders\Access\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 2026-10-10 browser test: a Super Admin role ticking Inventory screens saved
// nothing (newer permissions were dropped as "beyond your delegation"), and 54
// of 71 menu screens had no permission at all, so custom roles could not open them.
class ScreenPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(array $keys): User
    {
        $role = AccessRole::create(['name' => 'Screen Test', 'label' => 'Screen Test', 'code' => 'ACCESS-SCREEN-TEST', 'level' => 4, 'status' => 'active']);
        foreach ($keys as $key) {
            $role->permissions()->attach(AccessPermission::where('key', $key)->value('id'), [
                'allowed' => true, 'effect' => 'allow', 'delegable' => false, 'scope_type' => 'all', 'max_scope_type' => 'all', 'locked' => false,
            ]);
        }
        $user = User::factory()->create(['role' => null, 'access_mode' => 'advanced'])->fresh();
        $role->users()->attach($user->id, ['status' => 'active', 'starts_at' => now()->subMinute()]);
        app(AccessControlService::class)->flushUser($user);

        return $user;
    }

    public function test_every_screen_permission_points_at_a_real_page(): void
    {
        foreach (PermissionRegistry::SCREEN_PAGES as [$key, , , , , , $route]) {
            $this->assertTrue(app('router')->has($route), "$key -> $route");
        }
    }

    public function test_custom_role_opens_only_the_ticked_inventory_and_po_screens(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = $this->userWithRole(['inventory.stock.view', 'inventory.items.view', 'grn.purchase_orders.view']);
        $po = PurchaseOrder::factory()->create(['po_number' => 'PORM26270555']);

        $this->actingAs($user);
        $this->get(route('inventory.stock'))->assertOk()->assertSee('Inventory Summary');
        $this->get(route('inventory.items'))->assertOk();
        $this->get(route('admin.po'))->assertOk();
        // Downloads belong to the PO screen.
        $this->get(route('admin.po.excel', $po->po_number))->assertOk();
        $this->get(route('inventory.production'))->assertForbidden();
        $this->get(route('guard.dashboard'))->assertForbidden();
        $this->get(route('admin.users'))->assertForbidden();
    }

    public function test_super_admin_can_give_any_permission(): void
    {
        $this->seed(AccessControlSeeder::class);
        $super = User::factory()->create(['access_mode' => 'advanced'])->fresh();
        AccessRole::where('code', 'ACCESS-SUPER-ADMIN')->first()->users()->attach($super->id, ['status' => 'active', 'starts_at' => now()->subMinute()]);
        app(AccessControlService::class)->flushUser($super);

        $this->assertTrue(app(AccessControlService::class)->canDelegate($super, 'inventory.stock.view', ['scope_type' => 'all']));
        $plain = User::factory()->create(['access_mode' => 'advanced'])->fresh();
        $this->assertFalse(app(AccessControlService::class)->canDelegate($plain, 'inventory.stock.view', ['scope_type' => 'self']));
    }
}
