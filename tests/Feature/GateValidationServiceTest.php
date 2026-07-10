<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use App\Services\GateValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function baseForm(array $overrides = []): array
    {
        return [...[
            'entry_type' => 'inward',
            'po_number' => null,
            'vendor_name' => null,
            'vendor_gst' => null,
            'invoice_number' => null,
            'invoice_qty' => null,
            'rate' => null,
            'material' => null,
            'vehicle_number' => 'MH 01 AB 1234',
            'driver_name' => 'Test Driver',
            'transporter' => null,
            'location' => 'Bhiwandi',
            'gps' => '19.0 N, 73.0 E',
        ], ...$overrides];
    }

    public function test_missing_po_number_raises_hard_fail(): void
    {
        $issues = app(GateValidationService::class)->validate($this->baseForm());

        $this->assertContains('PO_MISSING', array_column($issues, 'code'));
    }

    public function test_duplicate_invoice_number_raises_hard_fail(): void
    {
        GateEntry::factory()->create(['invoice_number' => 'DUPLICATE-001']);

        $issues = app(GateValidationService::class)->validate($this->baseForm([
            'po_number' => 'PO TEST 001',
            'invoice_number' => 'DUPLICATE-001',
        ]));

        $this->assertContains('DUP_INVOICE', array_column($issues, 'code'));
    }

    public function test_rate_mismatch_against_real_po_master(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO TEST 002', 'vendor_name' => 'Test Vendor']);
        $po->lines()->create(['product' => 'Test Material', 'quantity' => 100, 'list_price' => 50]);
        SkuMaster::factory()->create(['sku' => 'Test Material', 'mapped' => true]);

        $issues = app(GateValidationService::class)->validate($this->baseForm([
            'po_number' => 'PO TEST 002',
            'material' => 'Test Material',
            'rate' => 999, // wrong on purpose
        ]));

        $this->assertContains('RATE_MISMATCH', array_column($issues, 'code'));
    }

    public function test_quantity_mismatch_against_real_po_master(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO TEST 003', 'vendor_name' => 'Test Vendor']);
        $po->lines()->create(['product' => 'Test Material', 'quantity' => 100, 'list_price' => 50]);
        SkuMaster::factory()->create(['sku' => 'Test Material', 'mapped' => true]);

        $issues = app(GateValidationService::class)->validate($this->baseForm([
            'po_number' => 'PO TEST 003',
            'material' => 'Test Material',
            'invoice_qty' => 1,
        ]));

        $this->assertContains('QTY_MISMATCH', array_column($issues, 'code'));
    }

    public function test_gst_mismatch_against_real_vendor_master(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO TEST 004', 'vendor_name' => 'Test Vendor']);
        $po->lines()->create(['product' => 'Test Material', 'quantity' => 100, 'list_price' => 50]);
        VendorMaster::factory()->create(['vendor_name' => 'Test Vendor', 'gst_number' => '27AAAAA0000A1Z5']);

        $issues = app(GateValidationService::class)->validate($this->baseForm([
            'po_number' => 'PO TEST 004',
            'vendor_gst' => 'WRONGGST0000A1Z5',
        ]));

        $this->assertContains('GST_MISMATCH', array_column($issues, 'code'));
    }

    public function test_unmapped_sku_raises_hard_fail(): void
    {
        SkuMaster::factory()->create(['sku' => 'Unmapped Material', 'mapped' => false]);

        $issues = app(GateValidationService::class)->validate($this->baseForm([
            'po_number' => 'PO TEST 005',
            'material' => 'Unmapped Material',
        ]));

        $this->assertContains('SKU_NOT_MAPPED', array_column($issues, 'code'));
    }

    public function test_pod_lr_early_when_vendor_has_not_submitted_lr_pod(): void
    {
        $issues = app(GateValidationService::class)->validate($this->baseForm(['po_number' => 'PO TEST 006']));

        $this->assertContains('POD_LR_EARLY', array_column($issues, 'code'));
    }

    public function test_no_pod_lr_issue_when_vendor_submission_has_lr_pod(): void
    {
        VendorSubmission::factory()->create(['po_number' => 'PO TEST 007', 'has_lr_pod' => true]);

        $issues = app(GateValidationService::class)->validate($this->baseForm(['po_number' => 'PO TEST 007']));

        $this->assertNotContains('POD_LR_EARLY', array_column($issues, 'code'));
    }

    public function test_clean_entry_with_no_material_still_raises_product_line_missing(): void
    {
        $issues = app(GateValidationService::class)->validate($this->baseForm(['po_number' => 'PO TEST 008']));

        $this->assertContains('PRODUCT_LINE_MISSING', array_column($issues, 'code'));
    }

    public function test_is_blocking_true_for_hard_fail_and_red_flag_only(): void
    {
        $service = app(GateValidationService::class);

        $this->assertTrue($service->isBlocking([['severity' => 'hardFail']]));
        $this->assertTrue($service->isBlocking([['severity' => 'redFlag']]));
        $this->assertFalse($service->isBlocking([['severity' => 'warning']]));
        $this->assertFalse($service->isBlocking([]));
    }
}
