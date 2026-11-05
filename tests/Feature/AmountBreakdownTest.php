<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorMaster;
use App\Services\AmountBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-13 client meeting: taxable value and GST shown separately.
class AmountBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The company's own state decides CGST+SGST (same state) vs IGST.
        \App\Models\Tan90\MasterData\LegalEntity::create(['code' => 'LE-T90', 'name' => 'Tan90 Thermal Solutions', 'gstin' => '33AAHCT1080F1ZN', 'country' => 'India', 'state' => 'Tamil Nadu', 'base_currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'fiscal_year' => 'Apr-Mar', 'status' => 'active', 'approval_status' => 'approved']);
    }

    private function po(string $state): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['shipping_state' => $state]);
        $po->lines()->create(['product' => 'RM A', 'quantity' => 10, 'uom' => 'KG', 'list_price' => 100, 'gst_rate' => 18]);
        $po->lines()->create(['product' => 'RM B', 'quantity' => 4, 'uom' => 'KG', 'list_price' => 50, 'gst_rate' => 5]);

        return $po->fresh('lines');
    }

    public function test_same_state_is_cgst_plus_sgst_and_other_state_is_igst(): void
    {
        $home = app(\App\Services\PurchaseOrderTaxService::class)->companyState();
        $b = app(AmountBreakdown::class)->forPo($this->po($home));
        $this->assertSame([1200.0, 95.0, 95.0, 0.0, 190.0, 1390.0], [$b['taxable'], $b['cgst'], $b['sgst'], $b['igst'], $b['gst'], $b['total']]);

        $other = $home === 'Karnataka' ? 'Gujarat' : 'Karnataka';
        $b = app(AmountBreakdown::class)->forPo($this->po($other));
        $this->assertSame([1200.0, 0.0, 0.0, 190.0, 1390.0], [$b['taxable'], $b['cgst'], $b['sgst'], $b['igst'], $b['total']]);
    }

    public function test_part_of_a_po_and_a_known_invoice_total_are_split_the_same_way(): void
    {
        $po = $this->po('Tamil Nadu');
        $lineA = $po->lines->firstWhere('product', 'RM A');
        $b = app(AmountBreakdown::class)->forPo($po, [$lineA->id => 5]);
        $this->assertSame([500.0, 590.0], [$b['taxable'], $b['total']]); // 5 of 10 at 18%

        $split = app(AmountBreakdown::class)->splitTotal(1390.0, $po);
        $this->assertSame([1200.0, 190.0], [$split['taxable'], $split['gst']]);
    }

    public function test_po_list_shows_taxable_and_gst(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $this->po('Tamil Nadu');
        Volt::test('admin.po-master')->assertSee('Taxable ₹1,200.00')->assertSee('GST ₹190.00');
    }

    public function test_po_pdf_and_receipt_tax_use_vendor_state_against_delivery_state(): void
    {
        VendorMaster::create([
            'vendor_name' => 'Maharashtra Supplier', 'gst_number' => '27ABCDE1234F1Z5', 'contact_phone' => '9000000000',
            'category' => 'Raw Material', 'active' => true, 'address_city' => 'Pune', 'address_state' => 'Maharashtra',
        ]);
        $po = PurchaseOrder::factory()->create([
            'vendor_name' => 'Maharashtra Supplier', 'billing_state' => 'Maharashtra', 'shipping_state' => 'Delhi',
        ]);
        $po->lines()->create(['product' => 'RM interstate', 'quantity' => 10, 'uom' => 'KG', 'list_price' => 100, 'gst_rate' => 18]);

        $document = app(\App\Services\PurchaseOrderDocumentService::class)->data($po->fresh('lines'));
        $this->assertSame([0.0, 0.0, 180.0], [$document['lineTaxes']->first()['cgst'], $document['lineTaxes']->first()['sgst'], $document['lineTaxes']->first()['igst']]);
        $breakdown = app(AmountBreakdown::class)->forPo($po->fresh('lines'));
        $this->assertSame([0.0, 0.0, 180.0], [$breakdown['cgst'], $breakdown['sgst'], $breakdown['igst']]);
    }
}
