<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\User;
use App\Support\CustomFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Client 2026-10-10: the team adds fields to any table themselves - pick the
// table, name the field, choose the data type.
class CustomFieldsAllTablesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'master:legal-entities';

    public function test_admin_adds_typed_fields_to_a_master_data_table_and_they_work_on_its_form(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin);
        $page = Volt::test('admin.custom-fields')->set('table', self::TABLE);
        $page->set('label', 'Credit Days')->set('type', 'number')->set('required', true)->call('add')->assertHasNoErrors();
        $page->set('label', 'Risk Class')->set('type', 'dropdown')->call('add')->assertHasErrors('options')
            ->set('options', 'Low, Medium, High')->call('add')->assertHasNoErrors();
        $page->set('label', 'Agreement Signed On')->set('type', 'date')->call('add')->assertHasNoErrors();
        $page->set('label', 'MSME Registered')->set('type', 'yes_no')->call('add')->assertHasNoErrors();
        $page->assertSee('Credit Days')->assertSee('Low, Medium, High');
        $this->assertSame(['credit_days', 'risk_class', 'agreement_signed_on', 'msme_registered'], CustomFields::fields(self::TABLE)->pluck('field_key')->all());

        // The form shows them and checks the data type.
        $this->actingAs($admin)->get(route('tan90.master-data.create', 'legal-entities'))->assertOk()
            ->assertSee('Credit Days')->assertSee('Risk Class')->assertSee('MSME Registered');
        $base = ['code' => 'T90-CF', 'name' => 'Custom Field Entity', 'base_currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'fiscal_year' => 'April-March'];
        $this->post(route('tan90.master-data.store', 'legal-entities'), $base + ['custom' => ['credit_days' => 'abc', 'risk_class' => 'Extreme']])
            ->assertSessionHasErrors(['custom.credit_days', 'custom.risk_class']);
        $this->post(route('tan90.master-data.store', 'legal-entities'), $base)->assertSessionHasErrors('custom.credit_days'); // must be filled

        $this->post(route('tan90.master-data.store', 'legal-entities'), $base + ['custom' => [
            'credit_days' => '45', 'risk_class' => 'Medium', 'agreement_signed_on' => '2026-10-01', 'msme_registered' => '1',
        ]])->assertRedirect();
        $entity = LegalEntity::where('code', 'T90-CF')->firstOrFail();
        $this->assertEquals(['credit_days' => 45, 'risk_class' => 'Medium', 'agreement_signed_on' => '2026-10-01', 'msme_registered' => true], CustomFields::values(self::TABLE, $entity->id));
        $this->get(route('tan90.master-data.show', ['legal-entities', $entity->id]))->assertOk()
            ->assertSee('Credit Days')->assertSee('45')->assertSee('01 Oct 2026')->assertSee('MSME Registered');

        // Editing changes the value.
        $this->put(route('tan90.master-data.update', ['legal-entities', $entity->id]), $base + ['custom' => ['credit_days' => '60', 'risk_class' => 'High']])->assertRedirect();
        $this->assertEquals(60, CustomFields::values(self::TABLE, $entity->id)['credit_days']);
    }

    public function test_only_super_admin_or_people_given_the_permission_manage_fields(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager, 'access_mode' => 'legacy'])->fresh();
        $this->actingAs($manager)->get(route('custom-fields.index'))->assertForbidden();

        AccessUserPermissionOverride::create([
            'user_id' => $manager->id, 'permission_id' => AccessPermission::where('key', CustomFields::PERMISSION)->value('id'),
            'granted_by' => $manager->id, 'allowed' => true, 'effect' => 'allow', 'scope_type' => 'all', 'starts_at' => now(), 'status' => 'active', 'reason' => 'Rollout fields',
        ]);
        $this->actingAs($manager)->get(route('custom-fields.index'))->assertOk()->assertSee('Custom Fields');
    }

    public function test_inventory_items_fields_from_the_same_screen_show_on_items(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin);
        Volt::test('admin.custom-fields')->set('table', CustomFields::INVENTORY_ITEMS)
            ->set('label', 'Shelf Life Until')->set('type', 'date')->call('add')->assertHasNoErrors();
        $item = InventoryItem::create(['sku' => 'Dated Item', 'item_type' => 'rm', 'unit' => 'KG']);

        Volt::test('inventory.items')->assertSee('Shelf Life Until')
            ->call('edit', $item->id)->set('customValues', ['shelf_life_until' => 'not a date'])->call('save')->assertHasErrors('customValues.shelf_life_until')
            ->set('customValues', ['shelf_life_until' => '2027-03-31'])->call('save')->assertHasNoErrors();
        $this->assertSame(['shelf_life_until' => '2027-03-31'], $item->fresh()->custom_fields);
    }
}
