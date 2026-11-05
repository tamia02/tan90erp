<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorSubmission;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Covers two client-reported bugs from a live manual QA pass:
 * (1) Guard's "Fetch" only checked vendor submissions and Zoho, never the
 *     app's own PO Master — so with Zoho slow/down, a PO already sitting in
 *     Admin -> PO Master was invisible at the gate.
 * (2) QC Check / GRN Check / Unloading Complete looked "broken" on a blank
 *     required field — the validation was already correct, but the forms
 *     never displayed the resulting error, so the button appeared to do
 *     nothing. Deliberately NOT RefreshDatabase: relies on the persistent
 *     demo users and seeded PurchaseOrder rows DatabaseSeeder creates (same
 *     pattern as GrnPipelineEndToEndTest). Run in isolation via --filter.
 */
class GuardFetchAndFormFeedbackTest extends TestCase
{
    public function test_guard_fetch_resolves_from_the_local_po_master(): void
    {
        $guard = User::where('email', 'guard@tan90.test')->firstOrFail();
        $poNumber = 'PO-LOCAL-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Local PO lookup QA',
            'vendor_name' => 'Sagar Safety & Industrial Supplies',
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
        ]);
        $po->lines()->create(['product' => 'Local Lookup SKU', 'quantity' => 300, 'uom' => 'PCS', 'list_price' => 26]);

        try {
            $this->actingAs($guard);
            $component = Volt::test('guard.bill-scan')
                ->set('entryType', 'inward')
                ->set('invoiceNumber', $poNumber)
                ->call('fetchBillDetails');

            $component->assertSet('fetched', true)
                ->assertSet('fetchedSource', 'PO Master')
                ->assertSet('poNumber', $poNumber)
                ->assertSet('vendorName', 'Sagar Safety & Industrial Supplies')
                ->assertSet('invoiceQty', '300')
                ->assertSet('rate', '26.00');
        } finally {
            $po->delete();
        }
    }

    public function test_guard_fetch_still_fails_clearly_for_an_unknown_number(): void
    {
        $guard = User::where('email', 'guard@tan90.test')->firstOrFail();

        $this->actingAs($guard);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('invoiceNumber', 'NO-SUCH-PO-EXISTS-ANYWHERE')
            ->call('fetchBillDetails')
            ->assertSet('fetched', false)
            ->assertHasErrors('invoiceNumber');
    }

    public function test_guard_fetch_and_gate_entry_keep_each_multi_sku_vendor_invoice_line(): void
    {
        $guard = User::where('email', 'guard@tan90.test')->firstOrFail();
        $vendorName = 'Sharp Polymer';
        $poNumber = 'PO-GUARD-LINES-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Guard multi-SKU line alignment',
            'vendor_name' => $vendorName,
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'released_at' => now(),
        ]);
        $firstLine = $po->lines()->create(['product' => 'GUARD-SKU-A', 'quantity' => 10, 'uom' => 'PCS', 'list_price' => 10]);
        $secondLine = $po->lines()->create(['product' => 'GUARD-SKU-B', 'quantity' => 20, 'uom' => 'BOX', 'list_price' => 20]);
        $invoiceNumber = 'GUARD-INV-'.now()->format('His').random_int(10, 99);
        $submission = VendorSubmission::create([
            'po_number' => $poNumber,
            'vendor_name' => $vendorName,
            'invoice_number' => $invoiceNumber,
            'invoice_qty' => 5,
            'invoice_amount' => 80,
            'material' => 'GUARD-SKU-A + GUARD-SKU-B',
            'line_quantities' => [$firstLine->id => 2, $secondLine->id => 3],
            'status' => 'submitted',
            'dispatched_at' => now(),
        ]);

        try {
            $this->actingAs($guard);
            $component = Volt::test('guard.bill-scan')
                ->set('entryType', 'inward')
                ->set('invoiceNumber', $invoiceNumber)
                ->call('fetchBillDetails')
                ->assertSet('poNumber', $poNumber)
                ->assertSet('vendorName', $vendorName)
                ->assertSet('productLines.0.sku', 'GUARD-SKU-A')
                ->assertSet('productLines.0.qty', '2')
                ->assertSet('productLines.0.uom', 'PCS')
                ->assertSet('productLines.1.sku', 'GUARD-SKU-B')
                ->assertSet('productLines.1.qty', '3')
                ->assertSet('productLines.1.uom', 'BOX')
                ->set('driverName', 'QA Guard Driver')
                ->set('driverPhone', '9999999999')
                ->set('vehicleNumber', 'QA-ALIGN-01')
                ->call('saveEntry')
                ->assertHasNoErrors();

            $gate = GateEntry::where('po_number', $poNumber)->latest('id')->firstOrFail();
            $this->assertSame('GUARD-SKU-A', $gate->product_lines[0]['sku']);
            $this->assertEquals(2.0, $gate->product_lines[0]['qty']);
            $this->assertSame('GUARD-SKU-B', $gate->product_lines[1]['sku']);
            $this->assertEquals(3.0, $gate->product_lines[1]['qty']);
            $this->assertStringContainsString($poNumber, $component->html());
            $gate->delete();
        } finally {
            $submission->delete();
            $po->delete();
        }
    }

    public function test_qc_check_shows_a_visible_error_on_a_blank_required_field(): void
    {
        $qc = User::where('email', 'qc@tan90.test')->firstOrFail();
        $gate = GateEntry::create([
            'entry_type' => 'inward', 'gate_no' => 'GATE-QCFORM-'.uniqid(), 'status' => 'grn',
            'material' => 'Test Material', 'invoice_qty' => 100,
            'vehicle_number' => 'TEST-VEH', 'driver_name' => 'Test Driver', 'location' => 'Test Location', 'sla_deadline' => now()->addHours(12),
        ]);

        $this->actingAs($qc);
        $component = Volt::test('qc.queue')
            ->call('openCheck', $gate->id)
            ->set('accepted', '') // left blank, as the QA report did
            ->set('qcHold', '')
            ->set('defective', '')
            ->set('rejected', '')
            ->call('submitCheck');

        $component->assertHasErrors(['accepted', 'qcHold', 'defective', 'rejected']);
        // The error must actually render on the page, not just exist in the
        // error bag — that's the entire point of the fix.
        $this->assertStringContainsString('required', strtolower($component->html()));
        $this->assertSame('grn', $gate->fresh()->status, 'A failed submission must not silently advance the gate entry.');
    }

    public function test_grn_check_shows_a_visible_error_on_a_blank_bin(): void
    {
        $storeManager = User::where('email', 'storemanager@tan90.test')->firstOrFail();
        $gate = GateEntry::create([
            'entry_type' => 'inward', 'gate_no' => 'GATE-GRNFORM-'.uniqid(), 'status' => 'qc_done',
            'material' => 'Test Material', 'invoice_qty' => 100,
            'vehicle_number' => 'TEST-VEH', 'driver_name' => 'Test Driver', 'location' => 'Test Location', 'sla_deadline' => now()->addHours(12),
        ]);
        $gate->qcResult()->create([
            'sku' => 'Test Material', 'po_qty' => 100, 'invoice_qty' => 100, 'physical_received' => 100,
            'accepted_qty' => 100, 'qc_hold_qty' => 0, 'defective_qty' => 0, 'rejected_qty' => 0, 'missing_qty' => 0,
        ]);

        $this->actingAs($storeManager);
        $component = Volt::test('grn.check')
            ->call('openPost', $gate->id)
            ->set('suggestedBin', '')
            ->call('post');

        $component->assertHasErrors('suggestedBin');
        $this->assertStringContainsString('required', strtolower($component->html()));
        $this->assertSame('qc_done', $gate->fresh()->status, 'A failed GRN post must not silently close the gate entry.');
    }

    public function test_unloading_complete_shows_a_visible_error_on_a_blank_box_count(): void
    {
        $storeExec = User::where('email', 'storeexec@tan90.test')->firstOrFail();
        $gate = GateEntry::create([
            'entry_type' => 'inward', 'gate_no' => 'GATE-UNLFORM-'.uniqid(), 'status' => 'unloading',
            'material' => 'Test Material', 'invoice_qty' => 100,
            'vehicle_number' => 'TEST-VEH', 'driver_name' => 'Test Driver', 'location' => 'Test Location', 'sla_deadline' => now()->addHours(12),
        ]);
        $gate->unloadingRecord()->create(['box_count' => 0, 'staging_area' => 'Staging Bay 1', 'unloaded_by' => 'Test Store Exec', 'started_at' => now()]);

        $this->actingAs($storeExec);
        $component = Volt::test('unloading.desk')
            ->set('completing', $gate->id)
            ->set('boxCount', '')
            ->call('completeUnloading', $gate->id);

        $component->assertHasErrors('boxCount');
        $this->assertStringContainsString('required', strtolower($component->html()));
        $this->assertSame('unloading', $gate->fresh()->status, 'A failed completion must not silently send the entry to QC.');
    }

    public function test_unloading_cannot_complete_with_only_a_typed_lr_reference_and_no_pod_file(): void
    {
        $storeExec = User::where('email', 'storeexec@tan90.test')->firstOrFail();
        $gate = GateEntry::create([
            'entry_type' => 'inward', 'gate_no' => 'GATE-PODREQ-'.uniqid(), 'status' => 'unloading',
            'material' => 'Test Material', 'invoice_qty' => 100,
            'vehicle_number' => 'TEST-VEH', 'driver_name' => 'Test Driver', 'location' => 'Test Location', 'sla_deadline' => now()->addHours(12),
        ]);
        $gate->unloadingRecord()->create(['box_count' => 0, 'staging_area' => 'Staging Bay 1', 'unloaded_by' => 'Test Store Exec', 'started_at' => now()]);

        $this->actingAs($storeExec);
        $component = Volt::test('unloading.desk')
            ->set('completing', $gate->id)
            ->set('boxCount', '10')
            ->set('podLrRef', 'LR-ONLY')
            ->call('completeUnloading', $gate->id);

        $component->assertHasErrors('podFile');
        $this->assertSame('unloading', $gate->fresh()->status, 'A typed LR reference must not bypass POD evidence capture.');
    }
}
