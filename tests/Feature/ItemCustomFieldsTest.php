<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\ItemCustomField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Meeting 2026-10-08: Admin adds custom item fields (Number / Text / Drop-down)
// and units come from one dropdown.
class ItemCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_adds_fields_and_store_manager_fills_them_in(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]));
        Volt::test('inventory.items')
            ->set('newFieldLabel', 'Cost Price')->set('newFieldType', 'number')->call('addCustomField')->assertHasNoErrors()
            ->set('newFieldLabel', 'Grade')->set('newFieldType', 'dropdown')->call('addCustomField')->assertHasErrors('newFieldOptions')
            ->set('newFieldOptions', 'A, B, , B')->set('newFieldRequired', true)->call('addCustomField')->assertHasNoErrors();
        $this->assertSame(['A', 'B'], ItemCustomField::where('field_key', 'grade')->value('options') ? ItemCustomField::where('field_key', 'grade')->first()->options : null);

        $item = InventoryItem::create(['sku' => 'Pouch 500g', 'item_type' => 'pm', 'unit' => 'PCS', 'opening_qty' => 0]);
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $page = Volt::test('inventory.items')->call('edit', $item->id)
            ->set('customValues', ['cost_price' => 'abc', 'grade' => 'Z'])->call('save')
            ->assertHasErrors(['customValues.cost_price', 'customValues.grade']);
        $page->set('customValues', ['cost_price' => '12.5', 'grade' => 'B'])->call('save')->assertHasNoErrors();
        $this->assertSame(['cost_price' => 12.5, 'grade' => 'B'], $item->fresh()->custom_fields);

        // Only Admin manages the field list.
        $page->call('addCustomField')->assertForbidden();
    }

    public function test_super_admin_can_give_a_store_manager_just_this_permission(): void
    {
        $permissionId = \App\Models\Access\AccessPermission::where('key', ItemCustomField::PERMISSION)->value('id');
        $this->assertNotNull($permissionId, 'permission is registered for Access Control');

        foreach (['legacy', 'advanced'] as $mode) {
            $manager = User::factory()->create(['role' => Role::StoreManager, 'access_mode' => $mode]);
            $this->actingAs($manager);
            Volt::test('inventory.items')->assertDontSee('Custom item fields -')
                ->set('newFieldLabel', 'Not Allowed')->call('addCustomField')->assertForbidden();

            $grant = \App\Models\Access\AccessUserPermissionOverride::create([
                'user_id' => $manager->id, 'permission_id' => $permissionId, 'granted_by' => $manager->id,
                'allowed' => true, 'effect' => 'allow', 'scope_type' => 'all', 'starts_at' => now(), 'status' => 'active', 'reason' => 'Owns item fields',
            ]);
            app(\App\Services\Access\AccessControlService::class)->flushUser($manager);
            Volt::test('inventory.items')->assertSee('Custom item fields -')
                ->set('newFieldLabel', "Moisture {$mode}")->call('addCustomField')->assertHasNoErrors();

            $grant->update(['revoked_at' => now(), 'status' => 'revoked', 'allowed' => false]);
            app(\App\Services\Access\AccessControlService::class)->flushUser($manager);
            $this->assertFalse(ItemCustomField::canManage($manager->fresh()));
        }
        $this->assertSame(2, ItemCustomField::where('label', 'like', 'Moisture %')->count());
    }

    public function test_units_come_from_the_units_master(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Admin]));
        Volt::test('inventory.items')->set('sku', 'Odd Unit Item')->set('unit', 'KGS')->call('save')->assertHasErrors('unit');
        Volt::test('inventory.items')->set('sku', 'Good Unit Item')->set('unit', 'kg')->call('save')->assertHasNoErrors();
        $this->assertSame('KG', InventoryItem::where('sku', 'Good Unit Item')->value('unit'));
    }
}
