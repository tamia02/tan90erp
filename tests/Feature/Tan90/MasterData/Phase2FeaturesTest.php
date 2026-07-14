<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\DataQualityIssue;
use App\Models\Tan90\MasterData\DataQualityRule;
use App\Models\Tan90\MasterData\LegalEntity;
use App\Models\Tan90\MasterData\NumberSeries;
use App\Models\Tan90\MasterData\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class Phase2FeaturesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_a_blank_code_field_is_auto_generated_from_a_matching_number_series(): void
    {
        $user = $this->masterDataManager();
        NumberSeries::create([
            'module' => 'Legal Entities', 'prefix' => 'LE-', 'pattern' => 'LE-{YYYY}-{####}',
            'next_number' => 7, 'reset_policy' => 'Yearly', 'status' => 'active',
        ]);

        $this->actingAs($user)->post(route('tan90.master-data.store', 'legal-entities'), [
            'name' => 'Auto Numbered Entity',
            'base_currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'fiscal_year' => 'April-March',
            // 'code' intentionally omitted
        ]);

        $entity = LegalEntity::where('name', 'Auto Numbered Entity')->first();
        $this->assertNotNull($entity, 'Record should have been created using the auto-generated code.');
        $this->assertStringStartsWith('LE-', $entity->code);

        $this->assertSame(8, NumberSeries::where('module', 'Legal Entities')->value('next_number'));
    }

    public function test_data_quality_scan_flags_a_vendor_with_no_gstin(): void
    {
        $user = $this->masterDataManager();
        DataQualityRule::create(['code' => 'DQ-VEN-GST', 'entity' => 'Vendor', 'description' => 'Missing GSTIN', 'default_severity' => 'critical']);
        Vendor::factory()->create(['gstin' => null, 'gst_status' => 'pending']);

        $this->actingAs($user)->post(route('tan90.master-data.data-quality.scan'));

        $this->assertDatabaseHas('tan90_data_quality_issues', [
            'rule_code' => 'DQ-VEN-GST',
            'resolution_status' => 'open',
        ]);
    }

    public function test_resolving_an_issue_marks_it_resolved_and_is_audited(): void
    {
        $user = $this->masterDataManager();
        $issue = DataQualityIssue::create([
            'rule_code' => 'DQ-VEN-GST', 'entity' => 'Vendor', 'record_label' => 'Test Vendor',
            'issue' => 'GSTIN missing', 'severity' => 'critical', 'resolution_status' => 'open', 'detected_at' => now(),
        ]);

        $this->actingAs($user)->post(route('tan90.master-data.data-quality.resolve', $issue->id));

        $this->assertSame('resolved', $issue->fresh()->resolution_status);
        $this->assertDatabaseHas('tan90_master_audit_logs', ['event' => 'RESOLVE']);
    }

    public function test_reference_entities_without_plant_scope_are_visible_to_every_viewer(): void
    {
        $auditor = $this->auditor();

        // Config/reference entities (e.g. number series) have no plant_scope_field,
        // so they're visible globally rather than being filtered by assigned plant.
        $this->actingAs($auditor)
            ->get(route('tan90.master-data.index', 'number-series'))
            ->assertOk();
    }

    public function test_config_entity_delete_is_a_hard_delete_with_no_restore_route(): void
    {
        $admin = $this->superAdmin();
        $series = NumberSeries::create([
            'module' => 'Disposable Series', 'pattern' => 'DS-{####}', 'next_number' => 1, 'status' => 'active',
        ]);

        $this->actingAs($admin)->delete(route('tan90.master-data.destroy', ['number-series', $series->id]));
        $this->assertDatabaseMissing('tan90_number_series', ['id' => $series->id]);

        $this->actingAs($admin)
            ->post(route('tan90.master-data.restore', ['number-series', $series->id]))
            ->assertNotFound();
    }
}
