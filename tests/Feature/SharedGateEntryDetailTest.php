<?php

namespace Tests\Feature;

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QcEvidenceImage;
use App\Models\QcResult;
use App\Models\UnloadingRecord;
use App\Models\User;
use App\Models\VendorSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Client-reported gap: Unloading Desk, QC, GRN and Finance screens could act
 * on a gate entry via their list cards, but had no way to open the full
 * record — only Guard's own guard.entries.show did that. This is the shared
 * page every other role now links to (gate-entries.show), with the same
 * role-based visibility already applied to Guard's own page: Guard sees only
 * its own submitted fields + validation issues; every other internal-staff
 * role sees the full downstream QC/GRN/Finance chain; Vendor is blocked from
 * opening another vendor's entry.
 */
class SharedGateEntryDetailTest extends TestCase
{
    use RefreshDatabase;

    private function seedDownstreamRecords(GateEntry $gate): void
    {
        $qc = QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Test SKU',
            'po_qty' => 100, 'invoice_qty' => 100, 'physical_received' => 100,
            'accepted_qty' => 90, 'rejected_qty' => 10, 'qc_hold_qty' => 0,
            'evidence_images' => ['qc-evidence/test-defect.jpg'], 'evidence_image_type' => 'Defective Image',
        ]);
        $qc->evidenceImageRecords()->create(['image_path' => 'qc-evidence/test-defect.jpg', 'image_type' => 'Defective Image']);
        GrnRecord::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Test SKU',
            'po_qty' => 100, 'invoice_qty' => 100, 'physical_received' => 100,
            'accepted_qty' => 90, 'qc_hold_qty' => 0, 'defective_qty' => 0, 'rejected_qty' => 10, 'missing_qty' => 0,
            'suggested_bin' => 'TEST-BIN-1', 'posted' => true,
        ]);
        FinanceRecord::create([
            'gate_entry_id' => $gate->id, 'invoice_number' => 'FIN-SHARED-1', 'vendor_name' => 'Test Vendor',
            'rate_per_unit' => 55.55, 'invoice_value' => 5555, 'accepted_value' => 4999,
            'final_payable' => 4999, 'vendor_status' => 'cleared',
        ]);
    }

    public function test_store_executive_sees_the_full_downstream_chain(): void
    {
        $gate = GateEntry::factory()->create(['gate_no' => 'GATE-SHARED-1', 'status' => 'closed']);
        $this->seedDownstreamRecords($gate);

        $user = User::factory()->create(['role' => 'storeExec']);
        $this->actingAs($user);

        $html = Volt::test('shared.gate-entry-detail', ['entry' => $gate])->assertOk()->html();

        $this->assertStringContainsString('QC result', $html);
        $this->assertStringContainsString('GRN record', $html);
        $this->assertStringContainsString('Finance record', $html);
        $this->assertStringContainsString('55.55', $html);
        $this->assertStringContainsString('Defective Image 1', $html);
        $this->assertStringContainsString(route('qc.evidence-image', QcEvidenceImage::first()), $html);
        $this->assertStringNotContainsString('qc-evidence/test-defect.jpg', $html);
        $this->assertStringNotContainsString('Evidence Image Records', $html);
    }

    public function test_guard_still_only_sees_base_fields_on_the_shared_page(): void
    {
        $gate = GateEntry::factory()->create(['gate_no' => 'GATE-SHARED-2', 'status' => 'closed']);
        $this->seedDownstreamRecords($gate);

        $user = User::factory()->create(['role' => 'guard']);
        $this->actingAs($user);

        $html = Volt::test('shared.gate-entry-detail', ['entry' => $gate])->assertOk()->html();

        $this->assertStringNotContainsString('QC result', $html);
        $this->assertStringNotContainsString('GRN record', $html);
        $this->assertStringNotContainsString('Finance record', $html);
        $this->assertStringNotContainsString('55.55', $html);
    }

    public function test_a_vendor_cannot_open_another_vendors_gate_entry(): void
    {
        $gate = GateEntry::factory()->create(['vendor_name' => 'Someone Elses Company']);
        $vendor = User::factory()->create(['name' => 'My Own Company', 'role' => 'vendor']);

        $this->actingAs($vendor)->get(route('gate-entries.show', $gate))->assertForbidden();
    }

    public function test_inward_detail_joins_po_invoice_and_unloading_without_irrelevant_blank_fields(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'DETAIL-PO-60', 'vendor_name' => 'Detail Vendor', 'subject' => 'PCM shipment',
            'po_date' => now()->toDateString(), 'due_date' => now()->addDays(3)->toDateString(),
            'status' => 'Released', 'billing_building' => 'Tan90 Plant', 'billing_city' => 'Bhiwandi',
            'billing_state' => 'Maharashtra', 'billing_zip' => '421302', 'shipping_city' => 'Bhiwandi',
            'shipping_state' => 'Maharashtra',
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product' => 'PCM Raw Compound', 'hsn_sac' => '39269099',
            'quantity' => 100, 'uom' => 'PCS', 'list_price' => 42,
        ]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'inward', 'status' => 'qc_done', 'po_number' => $po->po_number,
            'vendor_name' => $po->vendor_name, 'invoice_number' => 'INV-DETAIL-60', 'invoice_qty' => 60,
            'invoice_amount' => 2973.60, 'rate' => 42, 'material' => $line->product,
            'product_lines' => [['po_line_id' => $line->id, 'sku' => $line->product, 'name' => $line->product, 'qty' => 60, 'uom' => 'PCS', 'unit_price' => 42]],
        ]);
        UnloadingRecord::create([
            'gate_entry_id' => $gate->id, 'box_count' => 60, 'packing_unit' => 'Box',
            'staging_area' => 'Staging Bay 1', 'manpower_count' => 2, 'unloaded_by' => 'Store Team',
            'started_at' => now()->subMinute(), 'completed_at' => now(), 'pod_lr_ref' => 'LR-DETAIL-60',
        ]);
        VendorSubmission::create([
            'po_number' => $po->po_number, 'vendor_name' => $po->vendor_name,
            'invoice_number' => 'INV-DETAIL-OTHER', 'invoice_qty' => 40, 'status' => 'acknowledged',
        ]);
        VendorSubmission::create([
            'po_number' => $po->po_number, 'vendor_name' => $po->vendor_name,
            'invoice_number' => 'INV-DETAIL-60', 'invoice_qty' => 60, 'invoice_amount' => 2973.60,
            'status' => 'dispatched',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'storeExec']));

        $html = Volt::test('shared.gate-entry-detail', ['entry' => $gate])->assertOk()->html();

        $this->assertStringContainsString('Purchase order &amp; received items', $html);
        $this->assertStringContainsString('Tan90 Plant, Bhiwandi, Maharashtra, 421302', $html);
        $this->assertStringContainsString('PCM Raw Compound', $html);
        $this->assertStringContainsString('39269099', $html);
        $this->assertStringContainsString('60.00', $html);
        $this->assertStringContainsString('LR-DETAIL-60', $html);
        $this->assertStringContainsString('QC complete · awaiting GRN posting', $html);
        $this->assertStringContainsString('Submission matched', $html);
        $this->assertStringNotContainsString('INV-DETAIL-OTHER', $html);
        $this->assertStringNotContainsString('po_line_id', $html);
        $this->assertStringNotContainsString('Loading Window Start', $html);
        $this->assertStringNotContainsString('Expected Delivery Date', $html);
        $this->assertStringNotContainsString('Loaded At', $html);
        $this->assertStringNotContainsString('Exited At', $html);
    }

    public function test_store_executive_can_download_qc_evidence_but_guard_cannot(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('qc-evidence/store-exec-proof.jpg', 'test-image');
        $gate = GateEntry::factory()->create();
        $qc = QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Test SKU',
            'po_qty' => 1, 'invoice_qty' => 1, 'physical_received' => 1,
            'accepted_qty' => 1, 'qc_hold_qty' => 0, 'rejected_qty' => 0,
        ]);
        $image = $qc->evidenceImageRecords()->create(['image_path' => 'qc-evidence/store-exec-proof.jpg', 'image_type' => 'Defective Image']);

        $this->actingAs(User::factory()->create(['role' => 'storeExec']))
            ->get(route('qc.evidence-image', $image))
            ->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'guard']))
            ->get(route('qc.evidence-image', $image))
            ->assertForbidden();
    }
}
