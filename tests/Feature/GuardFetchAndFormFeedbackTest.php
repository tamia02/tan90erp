<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\User;
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

        $this->actingAs($guard);
        $component = Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('invoiceNumber', 'PO RM 2627 0018') // exists in the seeded PO Master, no vendor submission for it
            ->call('fetchBillDetails');

        $component->assertSet('fetched', true)
            ->assertSet('fetchedSource', 'PO Master')
            ->assertSet('poNumber', 'PO RM 2627 0018')
            ->assertSet('vendorName', 'Sagar Safety & Industrial Supplies')
            ->assertSet('invoiceQty', '300')
            ->assertSet('rate', '26.00');
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
}
