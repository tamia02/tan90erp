<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\Tan90\MasterData\MasterAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class MasterDataCrudTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_master_data_manager_can_create_a_legal_entity_as_draft(): void
    {
        $user = $this->masterDataManager();

        $response = $this->actingAs($user)->post(route('tan90.master-data.store', 'legal-entities'), [
            'code' => 'T90-TEST',
            'name' => 'Tan90 Test Entity',
            'base_currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'fiscal_year' => 'April-March',
        ]);

        $entity = LegalEntity::where('code', 'T90-TEST')->firstOrFail();
        $response->assertRedirect(route('tan90.master-data.show', ['legal-entities', $entity->id]));
        $this->assertSame('draft', $entity->approval_status);

        $this->assertDatabaseHas('tan90_master_audit_logs', [
            'event' => 'CREATE',
            'entity_id' => $entity->id,
        ]);
    }

    public function test_editing_a_non_critical_field_saves_directly(): void
    {
        $user = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'approved', 'state' => 'Tamil Nadu']);

        $this->actingAs($user)->put(route('tan90.master-data.update', ['legal-entities', $entity->id]), [
            'code' => $entity->code,
            'name' => $entity->name,
            'state' => 'Karnataka',
            'base_currency' => $entity->base_currency,
            'timezone' => $entity->timezone,
            'fiscal_year' => $entity->fiscal_year,
        ]);

        $this->assertSame('Karnataka', $entity->fresh()->state);
    }

    public function test_editing_a_critical_field_on_an_approved_record_opens_a_change_request_instead_of_saving(): void
    {
        $user = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'approved', 'gstin' => '33AAGCT9099K1ZP']);

        $this->actingAs($user)->put(route('tan90.master-data.update', ['legal-entities', $entity->id]), [
            'code' => $entity->code,
            'name' => $entity->name,
            'gstin' => '33AAGCT9099K1ZQ',
            'base_currency' => $entity->base_currency,
            'timezone' => $entity->timezone,
            'fiscal_year' => $entity->fiscal_year,
        ]);

        $this->assertSame('33AAGCT9099K1ZP', $entity->fresh()->gstin, 'GSTIN must not change until the change request is approved.');

        $this->assertDatabaseHas('tan90_master_change_requests', [
            'entity_type' => 'legal-entities',
            'entity_id' => $entity->id,
            'approval_status' => 'pending',
        ]);
    }

    public function test_archive_is_soft_delete_and_restore_brings_it_back(): void
    {
        $user = $this->masterDataManager();
        $entity = LegalEntity::factory()->create();

        $this->actingAs($user)->delete(route('tan90.master-data.destroy', ['legal-entities', $entity->id]));
        $this->assertSoftDeleted($entity);
        $this->assertSame('archived', $entity->fresh()->status);

        $this->actingAs($user)->post(route('tan90.master-data.restore', ['legal-entities', $entity->id]));
        $this->assertNotSoftDeleted($entity->fresh());
        $this->assertSame('active', $entity->fresh()->status);
    }
}
