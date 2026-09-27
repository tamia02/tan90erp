<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\NotificationCenter;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Covers the local outward chain without requiring a real dispatch record:
 * Guard save -> Store Manager approval/window/dock -> Store Executive
 * loading/QC/documents -> Guard exit -> role notification.
 * Run this class in isolation against the seeded testing database.
 */
class OutwardJourneyTest extends TestCase
{
    public function test_store_executive_has_a_single_shared_navigation_for_both_workstreams(): void
    {
        $executive = User::factory()->create(['role' => Role::StoreExec]);
        $this->actingAs($executive);

        Volt::test('unloading.desk')
            ->assertOk()
            ->assertSee('Loading &amp; Unloading', false)
            ->assertSee('Inward · Unloading')
            ->assertSee('Outward · Loading')
            ->assertSee('href="#inward-unloading"', false)
            ->assertSee('href="#outward-loading"', false)
            ->assertSee('Outward loading')
            ->assertSee('No outward dispatch is waiting for loading.');

        Volt::test('unloading.outward')
            ->assertOk()
            ->assertSee('Loading &amp; Unloading', false)
            ->assertSee('Inward · Unloading')
            ->assertSee('Outward · Loading')
            ->assertSee(route('unloading.desk'), false);
    }

    public function test_inward_ready_card_shows_only_the_start_step_before_completion(): void
    {
        $executive = User::factory()->create(['role' => Role::StoreExec]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'inward',
            'status' => 'allotted',
            'vendor_name' => 'Checklist QA Vendor',
            'material' => 'Checklist QA Material',
        ]);
        $gate->unloadingRecord()->create([
            'box_count' => 0,
            'staging_area' => 'Staging Bay 1',
            'unloaded_by' => $executive->name,
            'allotted_at' => now(),
        ]);

        try {
            $this->actingAs($executive);

            Volt::test('unloading.desk')
                ->set('starting', $gate->id)
                ->assertSee('Start unloading')
                ->assertSee('Manpower assigned')
                ->assertDontSee('POD evidence')
                ->assertDontSee('Bags / boxes received');
        } finally {
            $gate->unloadingRecord()->delete();
            $gate->delete();
        }
    }

    public function test_partial_multi_sku_outward_entry_saves_allocated_lines_and_inclusive_amount(): void
    {
        $guard = User::factory()->create(['role' => Role::Guard]);
        $poNumber = 'OUT-MULTI-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Outward partial allocation QA',
            'vendor_name' => 'Dispatch Customer E2E',
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
            'tax' => 252,
        ]);
        $firstLine = $po->lines()->create(['product' => 'OUT-SKU-A', 'quantity' => 60, 'uom' => 'PCS', 'list_price' => 10]);
        $secondLine = $po->lines()->create(['product' => 'OUT-SKU-B', 'quantity' => 40, 'uom' => 'BOX', 'list_price' => 20]);
        $invoice = 'OUT-MULTI-INV-'.now()->format('His').random_int(10, 99);

        try {
            $this->actingAs($guard);
            Volt::test('guard.bill-scan')
                ->set('entryType', 'outward')
                ->set('poNumber', $poNumber)
                ->call('fetchPurchaseOrderDetails')
                ->assertSee('Items on this dispatch')
                ->set('productLines.0.qty', '30')
                ->set('productLines.1.qty', '20')
                ->assertSet('invoiceQty', '50')
                ->assertSet('invoiceAmount', '826.00')
                ->set('driverName', 'Outward Split Driver')
                ->set('vehicleNumber', 'OUT-SPLIT-'.random_int(1000, 9999))
                ->set('invoiceNumber', $invoice)
                ->call('saveEntry')
                ->assertHasNoErrors();

            $gate = GateEntry::where('invoice_number', $invoice)->firstOrFail();
            $this->assertSame('50', (string) $gate->invoice_qty);
            $this->assertEquals(826.00, (float) $gate->invoice_amount);
            $this->assertSame((int) $firstLine->id, $gate->product_lines[0]['po_line_id']);
            $this->assertEquals(30.0, $gate->product_lines[0]['qty']);
            $this->assertSame((int) $secondLine->id, $gate->product_lines[1]['po_line_id']);
            $this->assertEquals(20.0, $gate->product_lines[1]['qty']);
        } finally {
            GateEntry::where('invoice_number', $invoice)->delete();
            $po->lines()->delete();
            $po->delete();
        }
    }

    public function test_partial_single_sku_outward_quantity_updates_saved_po_line_and_amount(): void
    {
        $guard = User::factory()->create(['role' => Role::Guard]);
        $poNumber = 'OUT-SINGLE-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Outward single-line allocation QA',
            'vendor_name' => 'Dispatch Customer E2E',
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
            'tax' => 756,
        ]);
        $line = $po->lines()->create(['product' => 'OUT-SKU-SINGLE', 'quantity' => 100, 'uom' => 'PCS', 'list_price' => 42]);
        $invoice = 'OUT-SINGLE-INV-'.now()->format('His').random_int(10, 99);

        try {
            $this->actingAs($guard);
            Volt::test('guard.bill-scan')
                ->set('entryType', 'outward')
                ->set('poNumber', $poNumber)
                ->call('fetchPurchaseOrderDetails')
                ->set('invoiceQty', '50')
                ->assertSet('invoiceAmount', '2478.00')
                ->assertSet('productLines.0.qty', '50')
                ->set('driverName', 'Outward Single Driver')
                ->set('vehicleNumber', 'OUT-SINGLE-'.random_int(1000, 9999))
                ->set('invoiceNumber', $invoice)
                ->call('saveEntry')
                ->assertHasNoErrors();

            $gate = GateEntry::where('invoice_number', $invoice)->firstOrFail();
            $this->assertSame('50', (string) $gate->invoice_qty);
            $this->assertEquals(2478.00, (float) $gate->invoice_amount);
            $this->assertSame((int) $line->id, $gate->product_lines[0]['po_line_id']);
            $this->assertEquals(50.0, $gate->product_lines[0]['qty']);
        } finally {
            GateEntry::where('invoice_number', $invoice)->delete();
            $po->lines()->delete();
            $po->delete();
        }
    }

    public function test_outward_entry_moves_through_approval_loading_exit_and_notifications(): void
    {
        $guard = User::factory()->create(['role' => Role::Guard]);
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $executive = User::factory()->create(['role' => Role::StoreExec]);

        $invoice = 'OUT-E2E-'.now()->format('His').random_int(100, 999);

        $this->actingAs($guard);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'outward')
            ->set('driverName', 'Outward E2E Driver')
            ->set('driverPhone', '+91 90000 00009')
            ->set('vehicleNumber', 'MH 04 OUT '.random_int(1000, 9999))
            ->set('poNumber', 'OUT-PACKAGE-E2E')
            ->set('vendorName', 'Customer Dispatch E2E')
            ->set('material', '500gm Blue Panel SP-2249')
            ->set('invoiceQty', '10')
            ->set('invoiceNumber', $invoice)
            ->call('saveEntry')
            ->assertHasNoErrors();

        $gate = GateEntry::where('invoice_number', $invoice)->firstOrFail();
        $this->assertSame('pending_validation', $gate->status);
        $this->assertSame('outward', $gate->entry_type);

        $this->actingAs($manager);
        Volt::test('store-manager.entry-approvals')
            ->call('openOutwardApproval', $gate->id)
            ->set('outwardDock', 'Dispatch Bay 1')
            ->set('loadingWindowStart', now()->subHour()->format('Y-m-d\\TH:i'))
            ->set('loadingWindowEnd', now()->addHour()->format('Y-m-d\\TH:i'))
            ->set('expectedDeliveryDate', now()->addDays(2)->toDateString())
            ->call('approveOutward', $gate->id)
            ->assertHasNoErrors();

        $this->assertSame('dock_assigned', $gate->fresh()->status);
        $this->assertSame('Dispatch Bay 1', $gate->fresh()->loading_dock);

        $this->actingAs($executive);
        Volt::test('unloading.outward')
            ->call('startLoading', $gate->id)
            ->assertHasNoErrors();
        $this->assertNotNull($gate->fresh()->loading_started_at);

        Volt::test('unloading.outward')
            ->call('openCompletion', $gate->id)
            ->set('documentsChecked.invoice', true)
            ->set('documentsChecked.bill', true)
            ->set('documentsChecked.pod', true)
            ->set('documentsChecked.lr', true)
            ->set('documentsPackConfirmed', true)
            ->call('completeLoading', $gate->id)
            ->assertHasNoErrors();

        $this->assertSame('loaded', $gate->fresh()->status);
        $this->assertTrue((bool) $gate->fresh()->dispatch_documents_shared);
        $this->assertNotNull($gate->fresh()->loaded_at);

        $this->actingAs($guard);
        Volt::test('guard.entry-detail', ['entry' => $gate->fresh()])
            ->call('confirmExit')
            ->assertHasNoErrors();

        $gate->refresh();
        $this->assertSame('dispatched', $gate->status);
        $this->assertNotNull($gate->exited_at);

        $this->actingAs($manager);
        $notices = NotificationCenter::forRole(Role::StoreManager);
        $this->assertTrue(collect($notices)->contains(fn (array $notice) => $notice['title'] === 'Dispatched today'));

        $gate->delete();
    }

    public function test_outward_loading_can_start_before_the_planned_window_and_records_actual_time(): void
    {
        $executive = User::factory()->create(['role' => Role::StoreExec]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'outward',
            'status' => 'dock_assigned',
            'loading_dock' => 'Dispatch Bay 1',
            'loading_window_start' => now()->addMinutes(10),
            'loading_window_end' => now()->addHour(),
        ]);

        $this->actingAs($executive);
        Volt::test('unloading.outward')
            ->call('startLoading', $gate->id)
            ->assertHasNoErrors();

        $this->assertNotNull($gate->fresh()->loading_started_at);
        $gate->delete();
    }

    public function test_outward_loading_requires_explicit_document_pack_confirmation(): void
    {
        $executive = User::factory()->create(['role' => Role::StoreExec]);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'outward',
            'status' => 'dock_assigned',
            'loading_started_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($executive);
        Volt::test('unloading.outward')
            ->call('openCompletion', $gate->id)
            ->set('documentsChecked.invoice', true)
            ->set('documentsChecked.bill', true)
            ->set('documentsChecked.pod', true)
            ->set('documentsChecked.lr', true)
            ->call('completeLoading', $gate->id)
            ->assertHasErrors('documentsPackConfirmed');

        $this->assertSame('dock_assigned', $gate->fresh()->status);
        $gate->delete();
    }
}
