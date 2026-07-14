<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Services\Tan90\MasterData\ApprovalService;
use App\Services\Tan90\MasterData\EntityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_submit_moves_a_draft_record_into_review(): void
    {
        $user = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'draft']);

        $this->actingAs($user)->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]));

        $this->assertSame('review', $entity->fresh()->approval_status);
        $this->assertDatabaseHas('tan90_master_audit_logs', ['event' => 'SUBMIT', 'entity_id' => $entity->id]);
    }

    public function test_master_data_manager_can_approve_a_record_in_review(): void
    {
        $approver = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.approve', ['legal-entities', $entity->id]));

        $this->assertSame('approved', $entity->fresh()->approval_status);
        $this->assertDatabaseHas('tan90_master_audit_logs', ['event' => 'APPROVE', 'entity_id' => $entity->id]);
    }

    public function test_reject_records_a_reason_and_keeps_the_record_editable(): void
    {
        $approver = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'review']);

        $this->actingAs($approver)->post(route('tan90.master-data.reject', ['legal-entities', $entity->id]), [
            'notes' => 'GSTIN mismatch, please recheck.',
        ]);

        $this->assertSame('rejected', $entity->fresh()->approval_status);
    }

    public function test_approving_a_change_request_applies_it_and_writes_a_version_snapshot(): void
    {
        $approver = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'approved', 'gstin' => 'OLDGSTIN0000000']);

        $changeRequest = app(ApprovalService::class)->requestCriticalChange(
            app(EntityRegistry::class)->get('legal-entities'),
            $entity,
            ['gstin' => 'NEWGSTIN0000000'],
            $approver,
            'Corrected GSTIN after audit.'
        );

        $this->actingAs($approver)->post(route('tan90.master-data.change-requests.approve', $changeRequest->id));

        $this->assertSame('NEWGSTIN0000000', $entity->fresh()->gstin);
        $this->assertSame('approved', $changeRequest->fresh()->approval_status);
        $this->assertDatabaseHas('tan90_master_change_versions', [
            'tan90_master_change_request_id' => $changeRequest->id,
            'version_number' => 1,
        ]);
    }

    public function test_rejecting_a_change_request_leaves_the_record_unchanged(): void
    {
        $approver = $this->masterDataManager();
        $entity = LegalEntity::factory()->create(['approval_status' => 'approved', 'gstin' => 'OLDGSTIN0000000']);

        $changeRequest = MasterChangeRequest::create([
            'request_no' => 'CR-TEST-0001',
            'entity_type' => 'legal-entities',
            'entity_id' => $entity->id,
            'proposed_changes' => ['gstin' => 'NEWGSTIN0000000'],
            'previous_values' => ['gstin' => 'OLDGSTIN0000000'],
            'requested_by' => $approver->id,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($approver)->post(route('tan90.master-data.change-requests.reject', $changeRequest->id), [
            'notes' => 'Not required.',
        ]);

        $this->assertSame('OLDGSTIN0000000', $entity->fresh()->gstin);
        $this->assertSame('rejected', $changeRequest->fresh()->approval_status);
    }
}
