<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\User;
use App\Models\VendorMaster;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Uses the persistent demo database, matching the existing Tan90 portal tests. */
class MultiSkuPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep this portal feature test self-contained. TestCase disables all
        // outbound Zoho credentials before the seeder runs.
        $this->seed(DatabaseSeeder::class);

        $compiled = storage_path('framework/test-views/multi-sku');
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config()->set('view.compiled', $compiled);
    }

    public function test_nature_of_po_is_the_first_qualification_and_derives_category_and_terms(): void
    {
        $manager = User::where('role', Role::StoreManager)->firstOrFail();
        $this->actingAs($manager);

        foreach ([
            'Trading' => 'FG',
            'Raw Material' => 'RM',
            'PM Packaging Material' => 'PM',
            'Others' => 'Other',
        ] as $nature => $category) {
            Volt::test('admin.po-master')
                ->set('purchaseNature', $nature)
                ->assertSet('materialType', $category)
                ->assertSet('paymentTerms', 'Net 30');
        }
    }

    public function test_store_manager_can_select_a_vendor_and_create_a_two_sku_po(): void
    {
        $manager = User::where('role', Role::StoreManager)->firstOrFail();
        $vendor = VendorMaster::where('vendor_name', 'Sharp Polymer')->firstOrFail();
        $poNumber = 'PO-MULTI-QA-'.now()->format('His').random_int(100, 999);

        $this->actingAs($manager);

        Volt::test('admin.po-master')
            ->set('purchaseNature', 'Raw Material')
            ->call('selectVendor', $vendor->vendor_name)
            ->assertSet('vendorName', $vendor->vendor_name)
            ->set('poNumber', $poNumber)
            ->set('subject', 'Two SKU vendor test order')
            ->set('items.0.product', 'QA SKU Alpha')
            ->set('items.0.hsnSac', '39269099')
            ->set('items.0.quantity', '10')
            ->set('items.0.uom', 'PCS')
            ->set('items.0.listPrice', '12.50')
            ->call('addItem')
            ->set('items.1.product', 'QA SKU Beta')
            ->set('items.1.hsnSac', '39269099')
            ->set('items.1.quantity', '25')
            ->set('items.1.uom', 'BOX')
            ->set('items.1.listPrice', '80')
            ->set('billingBuilding', 'Tan90 Test Office')
            ->set('billingStreet', 'GST Road')
            ->set('billingCity', 'Chennai')
            ->set('billingState', 'Tamil Nadu')
            ->set('billingZip', '600001')
            ->set('shippingState', 'Tamil Nadu')
            ->call('addPo')
            ->assertHasNoErrors();

        $po = PurchaseOrder::where('po_number', $poNumber)->with('lines')->firstOrFail();
        $this->assertSame(2, $po->lines->count());
        $this->assertSame(35.0, (float) $po->lines->sum('quantity'));
        $this->assertSame(['QA SKU Alpha', 'QA SKU Beta'], $po->lines->pluck('product')->all());
        $this->assertEquals(382.50, (float) $po->lines->sum('tax'));
        $this->assertEquals(2507.50, $po->grandTotal());
        $this->assertNull($po->submitted_at);

        $this->actingAs($manager)->get(route('admin.po.pdf', $poNumber))->assertOk()->assertHeader('content-type', 'application/pdf');

        Volt::test('admin.po-master')
            ->call('releasePo', $po->id)
            ->assertHasNoErrors();

        $po->refresh();
        $this->assertNotNull($po->released_at);
        $this->assertSame('Approved', $po->status);

        $po->delete();
    }

    public function test_save_and_submit_creates_a_reviewable_draft_then_release_exposes_it_to_vendor(): void
    {
        $manager = User::where('role', Role::StoreManager)->firstOrFail();
        $vendor = VendorMaster::where('vendor_name', 'Sharp Polymer')->firstOrFail();
        $poNumber = 'PO-SUBMIT-QA-'.now()->format('His').random_int(100, 999);

        $this->actingAs($manager);

        Volt::test('admin.po-master')
            ->set('purchaseNature', 'Raw Material')
            ->call('selectVendor', $vendor->vendor_name)
            ->set('poNumber', $poNumber)
            ->set('billingBuilding', 'Tan90 Test Office')
            ->set('billingStreet', 'GST Road')
            ->set('billingCity', 'Chennai')
            ->set('billingState', 'Tamil Nadu')
            ->set('billingZip', '600001')
            ->set('shippingState', 'Any temporary state label')
            ->set('reverseCharge', true)
            ->set('items.0.product', 'Reverse charge QA item')
            ->set('items.0.hsnSac', '563')
            ->set('items.0.quantity', '2')
            ->set('items.0.uom', 'KG')
            ->set('items.0.listPrice', '100')
            ->call('saveAndSubmit')
            ->assertHasNoErrors();

        $po = PurchaseOrder::where('po_number', $poNumber)->with('lines')->firstOrFail();
        $this->assertNotNull($po->submitted_at);
        $this->assertNull($po->released_at, 'Saving a PO must keep it hidden until the manager explicitly releases it.');
        $this->assertSame('Created', $po->status);
        $this->assertSame(0.0, (float) $po->lines->first()->tax);
        $this->assertSame(0.0, $po->taxTotal());

        Volt::test('admin.po-master')
            ->call('releasePo', $po->id)
            ->assertHasNoErrors();

        $po->refresh();
        $this->assertNotNull($po->released_at);
        $this->assertSame('Approved', $po->status);

        $this->actingAs(User::where('email', 'vendor.sharp@tan90.test')->firstOrFail());
        Volt::test('vendor.purchase-orders')
            ->assertSee($poNumber)
            ->assertSee('Accept PO');
        $po->delete();
    }

    public function test_vendor_dashboard_exposes_recent_po_and_bill_actions(): void
    {
        $vendor = User::where('email', 'vendor.sharp@tan90.test')->firstOrFail();

        $this->actingAs($vendor);

        Volt::test('vendor.dashboard')
            ->assertSee('POs released today')
            ->assertSee('Recent POs')
            ->assertSee('View details')
            ->assertSee('PO PDF')
            ->assertSee('Bill preview')
            ->assertSee('Download bill');
    }

    public function test_vendor_dashboard_exposes_direct_actions_for_a_new_released_po(): void
    {
        $vendor = User::where('email', 'vendor.sharp@tan90.test')->firstOrFail();
        $poNumber = 'PO-ACTION-QA-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Action center QA order',
            'vendor_name' => $vendor->name,
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'released_at' => now(),
        ]);
        $po->lines()->create([
            'product' => 'Action center QA SKU',
            'quantity' => 5,
            'uom' => 'PCS',
            'list_price' => 10,
        ]);

        try {
            $this->actingAs($vendor);

            Volt::test('vendor.dashboard')
                ->assertSee('Action center')
                ->assertSee('POs waiting for your response')
                ->assertSee('Accept PO')
                ->assertSee('Reject PO')
                ->call('acknowledge', $poNumber, true)
                ->assertHasNoErrors();

            $this->assertDatabaseHas('purchase_order_acknowledgements', [
                'po_number' => $poNumber,
                'vendor_name' => $vendor->name,
                'accepted' => true,
            ]);
        } finally {
            PurchaseOrderAcknowledgement::where('po_number', $poNumber)->delete();
            $po->delete();
        }
    }

    public function test_accepted_po_is_visible_in_my_submissions_action_queue(): void
    {
        $vendor = User::where('email', 'vendor.sharp@tan90.test')->firstOrFail();
        $poNumber = 'PO-ACCEPTED-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Accepted queue QA order',
            'vendor_name' => $vendor->name,
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'released_at' => now(),
        ]);
        $po->lines()->create([
            'product' => 'Accepted queue QA SKU',
            'quantity' => 12,
            'uom' => 'PCS',
            'list_price' => 25,
        ]);

        try {
            $this->actingAs($vendor);

            Volt::test('vendor.purchase-orders')
                ->call('acknowledge', $poNumber, true)
                ->assertHasNoErrors();

            Volt::test('vendor.submissions')
                ->assertSee('Accepted POs')
                ->assertSee($poNumber)
                ->assertSee('Upload invoice / dispatch')
                ->call('startSubmission', $poNumber)
                ->assertSet('po_number', $poNumber)
                ->assertSee('Dispatch submission')
                ->call('clearSubmissionForm')
                ->assertSet('po_number', '');
        } finally {
            PurchaseOrderAcknowledgement::where('po_number', $poNumber)->delete();
            $po->delete();
        }
    }

    public function test_vendor_switching_between_po_numbers_recalculates_multi_sku_invoice_lines(): void
    {
        $vendor = User::where('email', 'vendor.sharp@tan90.test')->firstOrFail();
        $singleSku = PurchaseOrder::create([
            'po_number' => 'PO-ALIGN-SINGLE-'.now()->format('His').random_int(100, 999),
            'subject' => 'Single SKU alignment check',
            'vendor_name' => $vendor->name,
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
        ]);
        $singleSku->lines()->create(['product' => 'ALIGN-A', 'quantity' => 12, 'uom' => 'PCS', 'list_price' => 10, 'tax' => 12]);

        $multiSku = PurchaseOrder::create([
            'po_number' => 'PO-ALIGN-MULTI-'.now()->format('His').random_int(100, 999),
            'subject' => 'Multi SKU alignment check',
            'vendor_name' => $vendor->name,
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
        ]);
        $firstLine = $multiSku->lines()->create(['product' => 'ALIGN-B1', 'quantity' => 10, 'uom' => 'PCS', 'list_price' => 10, 'tax' => 20]);
        $secondLine = $multiSku->lines()->create(['product' => 'ALIGN-B2', 'quantity' => 20, 'uom' => 'PCS', 'list_price' => 20, 'tax' => 40]);

        foreach ([$singleSku, $multiSku] as $po) {
            PurchaseOrderAcknowledgement::create([
                'po_number' => $po->po_number,
                'vendor_name' => $vendor->name,
                'accepted' => true,
                'acknowledged_by' => $vendor->id,
                'acknowledged_at' => now(),
                'production_started_at' => now(),
            ]);
        }

        VendorSubmission::create([
            'po_number' => $multiSku->po_number,
            'vendor_name' => $vendor->name,
            'invoice_number' => 'ALIGN-LEGACY-'.now()->format('His').random_int(10, 99),
            'invoice_qty' => 5,
            'invoice_amount' => 150,
            'material' => 'ALIGN-B1 + ALIGN-B2',
            'status' => 'submitted',
            'dispatched_at' => now(),
        ]);

        try {
            $this->actingAs($vendor);
            $component = Volt::test('vendor.submissions')
                ->call('startSubmission', $singleSku->po_number)
                ->assertSet('invoice_qty', 12)
                ->assertSet('invoice_amount', 132.0)
                ->set('po_number', $multiSku->po_number)
                ->assertSet('invoice_qty', null)
                ->assertSet('invoice_amount', 0.0)
                ->set("submissionLineQuantities.{$firstLine->id}", 2)
                ->set("submissionLineQuantities.{$secondLine->id}", 3)
                ->assertSet('invoice_qty', 5)
                ->assertSet('invoice_amount', 90.0)
                ->assertSet('material', 'ALIGN-B1 + ALIGN-B2')
                ->assertSee('Prior invoice quantities for this multi-SKU PO do not have a recorded SKU split');
        } finally {
            VendorSubmission::whereIn('po_number', [$singleSku->po_number, $multiSku->po_number])->delete();
            PurchaseOrderAcknowledgement::whereIn('po_number', [$singleSku->po_number, $multiSku->po_number])->delete();
            $singleSku->delete();
            $multiSku->delete();
        }
    }
}
