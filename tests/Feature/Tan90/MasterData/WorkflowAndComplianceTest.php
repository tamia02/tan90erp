<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\ApprovalProgress;
use App\Models\Tan90\MasterData\ApprovalWorkflow;
use App\Models\Tan90\MasterData\ApprovalWorkflowStep;
use App\Models\Tan90\MasterData\DocumentRule;
use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\Tan90\MasterData\MasterAuditLog;
use App\Models\Tan90\MasterData\SlaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class WorkflowAndComplianceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_multi_step_workflow_requires_each_step_role_in_order(): void
    {
        $mdm = $this->masterDataManager();
        $qc = $this->userWithNamedRole('QC', ['view', 'approve']);
        $finance = $this->userWithNamedRole('Finance', ['view', 'approve']);

        $workflow = ApprovalWorkflow::create([
            'code' => 'WF-TEST', 'name' => 'Legal Entity Test Workflow', 'module' => 'Legal Entities',
            'trigger' => 'Create', 'approval_status' => 'active',
        ]);
        ApprovalWorkflowStep::create(['code' => 'WF-TEST-1', 'tan90_approval_workflow_id' => $workflow->id, 'step_order' => 1, 'step_role' => 'QC']);
        ApprovalWorkflowStep::create(['code' => 'WF-TEST-2', 'tan90_approval_workflow_id' => $workflow->id, 'step_order' => 2, 'step_role' => 'Finance']);

        $entity = LegalEntity::factory()->create(['approval_status' => 'draft']);

        $this->actingAs($mdm)->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]));
        $this->assertSame('review', $entity->fresh()->approval_status);

        // Master Data Manager isn't "QC" - the first step - so approval should be blocked.
        $this->actingAs($mdm)
            ->post(route('tan90.master-data.approve', ['legal-entities', $entity->id]))
            ->assertSessionHasErrors('approval');
        $this->assertSame('review', $entity->fresh()->approval_status, 'Wrong-role approval must not change status.');

        // QC approves step 1 - record should still be "review", now waiting on Finance.
        $this->actingAs($qc)->post(route('tan90.master-data.approve', ['legal-entities', $entity->id]));
        $this->assertSame('review', $entity->fresh()->approval_status);

        $progress = ApprovalProgress::where('entity_type', 'legal-entities')->where('entity_id', $entity->id)->first();
        $this->assertSame(2, $progress->current_step_order);

        // Finance approves the final step - now it's fully approved.
        $this->actingAs($finance)->post(route('tan90.master-data.approve', ['legal-entities', $entity->id]));
        $this->assertSame('approved', $entity->fresh()->approval_status);
        $this->assertSame('approved', $progress->fresh()->status);
    }

    public function test_submission_is_blocked_until_mandatory_documents_are_uploaded(): void
    {
        Storage::fake('local');
        $mdm = $this->masterDataManager();
        DocumentRule::create(['code' => 'DOC-LE-TEST', 'name' => 'Legal Entity Docs', 'entity' => 'Legal Entities', 'mandatory' => 'GST Certificate, PAN Card']);

        $entity = LegalEntity::factory()->create(['approval_status' => 'draft']);

        $this->actingAs($mdm)
            ->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]))
            ->assertSessionHasErrors('documents');
        $this->assertSame('draft', $entity->fresh()->approval_status, 'Submission must not proceed while documents are missing.');

        $this->actingAs($mdm)->post(route('tan90.master-data.attachments.store', ['legal-entities', $entity->id]), [
            'document_label' => 'GST Certificate',
            'file' => UploadedFile::fake()->create('gst.pdf', 100),
        ]);
        $this->actingAs($mdm)->post(route('tan90.master-data.attachments.store', ['legal-entities', $entity->id]), [
            'document_label' => 'PAN Card',
            'file' => UploadedFile::fake()->create('pan.pdf', 100),
        ]);

        $this->actingAs($mdm)->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]));
        $this->assertSame('review', $entity->fresh()->approval_status, 'Submission should succeed once all mandatory documents are attached.');
    }

    public function test_sla_check_command_escalates_a_long_pending_approval(): void
    {
        $mdm = $this->masterDataManager();
        SlaPolicy::create([
            'code' => 'SLA-MASTER-NEW', 'name' => 'New Master Approval', 'applies_to' => 'All new master records',
            'target' => '24 Hours', 'warning_at' => '1 Hours', 'escalate_at' => '2 Hours',
            'escalation_role' => 'Finance', 'calendar' => 'Business Hours', 'status' => 'active',
        ]);

        $entity = LegalEntity::factory()->create(['approval_status' => 'draft']);
        $this->actingAs($mdm)->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]));

        $progress = ApprovalProgress::where('entity_type', 'legal-entities')->where('entity_id', $entity->id)->first();
        $progress->update(['submitted_at' => now()->subHours(3)]);

        Artisan::call('tan90:check-sla-breaches');

        $this->assertNotNull($progress->fresh()->sla_warned_at);
        $this->assertNotNull($progress->fresh()->sla_escalated_at);
        $this->assertDatabaseHas('tan90_master_audit_logs', ['event' => 'SLA_ESCALATE']);
    }

    public function test_sla_check_command_is_idempotent_and_does_not_re_escalate(): void
    {
        $mdm = $this->masterDataManager();
        SlaPolicy::create([
            'code' => 'SLA-MASTER-NEW', 'name' => 'New Master Approval', 'applies_to' => 'All new master records',
            'target' => '24 Hours', 'warning_at' => '1 Hours', 'escalate_at' => '2 Hours',
            'escalation_role' => 'Finance', 'calendar' => 'Business Hours', 'status' => 'active',
        ]);

        $entity = LegalEntity::factory()->create(['approval_status' => 'draft']);
        $this->actingAs($mdm)->post(route('tan90.master-data.submit', ['legal-entities', $entity->id]));
        ApprovalProgress::where('entity_type', 'legal-entities')->where('entity_id', $entity->id)
            ->update(['submitted_at' => now()->subHours(3)]);

        Artisan::call('tan90:check-sla-breaches');
        Artisan::call('tan90:check-sla-breaches');

        $this->assertSame(1, MasterAuditLog::where('event', 'SLA_ESCALATE')->count());
    }
}
