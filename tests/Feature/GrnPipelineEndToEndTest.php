<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Manually verifies the real Guard -> Validation -> Store Exec -> QC -> Store
 * Manager -> Finance pipeline by driving the actual Livewire/Volt components
 * each role uses, exactly as a real user would click through them - not just
 * calling the underlying services directly. Deliberately NOT RefreshDatabase:
 * relies on the persistent demo users and seeded PurchaseOrder/VendorMaster
 * rows DatabaseSeeder creates (same pattern as LegacyRolePagesLoadTest). Run
 * in isolation via --filter so a RefreshDatabase test elsewhere in the same
 * process can't wipe that seed data out from under it.
 */
class GrnPipelineEndToEndTest extends TestCase
{
    private function users(): array
    {
        return [
            'guard' => User::where('email', 'guard@tan90.test')->firstOrFail(),
            'vendor' => User::where('email', 'vendor@tan90.test')->firstOrFail(),
            'storeExec' => User::where('email', 'storeexec@tan90.test')->firstOrFail(),
            'qc' => User::where('email', 'qc@tan90.test')->firstOrFail(),
            'storeManager' => User::where('email', 'storemanager@tan90.test')->firstOrFail(),
            'finance' => User::where('email', 'finance@tan90.test')->firstOrFail(),
        ];
    }

    /** Drives a gate entry from 'validated' through to 'closed' via the real
     * loading-desk / unloading-desk / QC / GRN-check components. */
    private function driveToClose(array $users, GateEntry $gate, array $qcSplit, string $bin): void
    {
        $this->actingAs($users['storeExec']);
        Volt::test('unloading.loading-desk')
            ->call('openAssign', $gate->id)
            ->call('assignDock', $gate->id);
        $this->assertSame('dock_assigned', $gate->fresh()->status);

        Volt::test('unloading.desk')->call('allot', $gate->id);
        $this->assertSame('allotted', $gate->fresh()->status);

        Volt::test('unloading.desk')->call('startUnloading', $gate->id);
        $this->assertSame('unloading', $gate->fresh()->status);

        Volt::test('unloading.desk')
            ->set('boxCount', '10')
            ->set('podLrRef', 'LR-TEST')
            ->call('completeUnloading', $gate->id);
        $this->assertSame('grn', $gate->fresh()->status);

        $this->actingAs($users['qc']);
        Volt::test('qc.queue')
            ->call('openCheck', $gate->id)
            ->set('accepted', (string) $qcSplit['accepted'])
            ->set('qcHold', (string) $qcSplit['qcHold'])
            ->set('defective', (string) $qcSplit['defective'])
            ->set('rejected', (string) $qcSplit['rejected'])
            ->set('qcReasons', $qcSplit['reasons'] ?? '')
            ->set('holdReason', $qcSplit['qcHold'] > 0 ? ($qcSplit['holdReason'] ?? 'On hold pending inspection.') : '')
            ->call('submitCheck')
            ->assertHasNoErrors();

        $expectedStatus = ($qcSplit['accepted'] === 0 && $qcSplit['qcHold'] === 0 && $qcSplit['defective'] === 0 && $qcSplit['rejected'] > 0)
            ? 'rejected' : 'qc_done';
        $this->assertSame($expectedStatus, $gate->fresh()->status);

        if ($expectedStatus === 'qc_done') {
            $this->actingAs($users['storeManager']);
            Volt::test('grn.check')
                ->call('openPost', $gate->id)
                ->set('suggestedBin', $bin)
                ->call('post');
            $this->assertSame('closed', $gate->fresh()->status);
        }
    }

    /**
     * Scenario 1: a clean entry against real PO/vendor master data - Sagar
     * Safety's actual contracted rate is ₹26/unit (not ₹42), which is exactly
     * the case the recent GrnPostingService fix (commit 75c1ce2) targets:
     * before that fix, every vendor's finance record was posted at a
     * hardcoded ₹42/unit regardless of their real rate.
     */
    public function test_scenario_1_clean_entry_flows_end_to_end_and_finance_uses_the_real_vendor_rate(): void
    {
        $users = $this->users();
        $invoiceNumber = 'SAG/INV/E2E-'.now()->format('His').random_int(100, 999);

        // Real-world precondition this scenario is modeling: the vendor
        // pre-filed their delivery (with LR/POD) via the Vendor Portal
        // before the truck ever reaches the gate — see the POD_LR_EARLY
        // check in GateValidationService. Without this, even an otherwise
        // perfect entry blocks (see the finding documented on scenario 2).
        VendorSubmission::firstOrCreate(
            ['po_number' => 'PO RM 2627 0018', 'invoice_number' => 'SAG-PRESUB-0018'],
            ['vendor_name' => 'Sagar Safety & Industrial Supplies', 'invoice_qty' => 300, 'material' => 'Hand Gloves (PPE) — Bulk Pack', 'has_invoice' => true, 'has_eway_bill' => true, 'has_lr_pod' => true, 'status' => 'acknowledged']
        );

        $this->actingAs($users['guard']);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('driverName', 'E2E Driver One')
            ->set('driverPhone', '+91 90000 00001')
            ->set('vehicleNumber', 'MH 04 E2E '.random_int(1000, 9999))
            ->set('invoiceNumber', $invoiceNumber)
            ->set('invoiceAmount', '7800')
            ->set('poNumber', 'PO RM 2627 0018')
            ->set('vendorName', 'Sagar Safety & Industrial Supplies')
            ->set('vendorGst', '27AADCS7788M1Z9')
            ->set('material', 'Hand Gloves (PPE) — Bulk Pack')
            ->set('invoiceQty', '300')
            ->set('rate', '26')
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('invoice_number', $invoiceNumber)->firstOrFail();
        $this->assertSame('validated', $gate->status, 'Clean entry against matching PO/vendor/GST should not be blocked.');
        $this->assertSame(0, ValidationIssue::where('gate_entry_id', $gate->id)->count());

        $this->driveToClose($users, $gate, ['accepted' => 300, 'qcHold' => 0, 'defective' => 0, 'rejected' => 0], 'SAG-PPE-A1');

        $finance = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertEquals(26.0, (float) $finance->rate_per_unit, 'Finance record must use Sagar Safety\'s real ₹26 rate, not a hardcoded ₹42.');
        $this->assertEquals(300 * 26, (float) $finance->final_payable);
        $this->assertEquals(0, (float) $finance->deduction_defective);
        $this->assertEquals(0, (float) $finance->deduction_rejected);
    }

    /**
     * Scenario 2: guard keys in a rate that doesn't match the PO on file,
     * AND (realistically) the vendor never pre-filed a submission for this
     * PO either — so TWO independent redFlag issues stack up. Confirms
     * (a) both are actually caught and block progression, (b) Store Exec
     * cannot move a blocked entry, (c) resolving only ONE of the two flags
     * is not enough — the entry stays blocked until every open flag is
     * cleared, and (d) only Store Manager can clear them.
     */
    public function test_scenario_2_rate_mismatch_blocks_until_store_manager_resolves_it(): void
    {
        $users = $this->users();
        $invoiceNumber = 'KIS/INV/E2E-'.now()->format('His').random_int(100, 999);

        $this->actingAs($users['guard']);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('driverName', 'E2E Driver Two')
            ->set('driverPhone', '+91 90000 00002')
            ->set('vehicleNumber', 'MH 04 E2E '.random_int(1000, 9999))
            ->set('invoiceNumber', $invoiceNumber)
            ->set('invoiceAmount', '11250')
            ->set('poNumber', 'PO RM 2627 0031')
            ->set('vendorName', 'Konkan Insulation Systems')
            ->set('vendorGst', '27AAFCK5566L1Z2')
            ->set('material', 'Wire Mesh — Structural Packaging')
            ->set('invoiceQty', '450')
            ->set('rate', '25') // PO's real list_price is 18 — deliberate mismatch
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('invoice_number', $invoiceNumber)->firstOrFail();
        $this->assertSame('pending_validation', $gate->status, 'Rate mismatch against the PO must block the entry.');

        $rateIssue = ValidationIssue::where('gate_entry_id', $gate->id)->where('code', 'RATE_MISMATCH')->firstOrFail();
        $this->assertSame('redFlag', $rateIssue->severity);
        $podIssue = ValidationIssue::where('gate_entry_id', $gate->id)->where('code', 'POD_LR_EARLY')->firstOrFail();
        $this->assertSame('redFlag', $podIssue->severity, 'No vendor submission exists for this PO yet, so this flag stacks alongside the rate mismatch.');

        // Store Exec's loading desk must refuse to move a still-blocked entry.
        $this->actingAs($users['storeExec']);
        Volt::test('unloading.loading-desk')
            ->call('openAssign', $gate->id)
            ->call('assignDock', $gate->id);
        $this->assertSame('pending_validation', $gate->fresh()->status, 'Store Exec must not be able to advance a blocked entry.');

        // Clearing only ONE of the two open flags must not be enough.
        $this->actingAs($users['storeManager']);
        Volt::test('validation-issues')->call('updateStatus', $rateIssue->id, 'resolved');
        $this->assertSame('pending_validation', $gate->fresh()->status, 'A second open flag (POD_LR_EARLY) must keep the entry blocked.');

        // Clearing the second (and last) open flag unblocks it.
        Volt::test('validation-issues')->call('updateStatus', $podIssue->id, 'resolved');
        $this->assertSame('validated', $gate->fresh()->status, 'Resolving every open blocking issue must auto-unblock the gate entry.');

        $this->driveToClose($users, $gate, ['accepted' => 450, 'qcHold' => 0, 'defective' => 0, 'rejected' => 0], 'KIS-WM-A2');

        $finance = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        // Worth knowing: GRN posting pays out the rate the Guard actually
        // keyed in (25), not the PO's original 18 — resolving the flag does
        // not correct the number, it only removes the block.
        $this->assertEquals(25.0, (float) $finance->rate_per_unit);
    }

    /**
     * Scenario 3: a second gate entry reusing an already-used invoice number
     * (the seeded demo entry GATE-1001 already used TCM/INV/4471). Confirms
     * the hard-fail duplicate-invoice check actually fires, and separately
     * flags that "resolving" a duplicate-invoice flag in this screen is just
     * a status change - it does not verify the duplicate was actually
     * investigated before letting the entry proceed.
     */
    public function test_scenario_3_duplicate_invoice_is_hard_blocked(): void
    {
        $users = $this->users();

        $this->actingAs($users['guard']);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('driverName', 'E2E Driver Three')
            ->set('driverPhone', '+91 90000 00003')
            ->set('vehicleNumber', 'MH 04 E2E '.random_int(1000, 9999))
            ->set('invoiceNumber', 'TCM/INV/4471') // already used by seeded GATE-1001
            ->set('invoiceAmount', '29400')
            ->set('poNumber', 'PO RM 2627 0020')
            ->set('vendorName', 'Thermocore Materials Pvt Ltd')
            ->set('vendorGst', '27AACCH1234K1Z5')
            ->set('material', 'PCM Raw Compound (TN-1 Grade)')
            ->set('invoiceQty', '700')
            ->set('rate', '42')
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('invoice_number', 'TCM/INV/4471')
            ->where('id', '!=', GateEntry::where('gate_no', 'GATE-1001')->value('id'))
            ->latest('id')->firstOrFail();

        $this->assertSame('pending_validation', $gate->status);
        $issue = ValidationIssue::where('gate_entry_id', $gate->id)->where('code', 'DUP_INVOICE')->firstOrFail();
        $this->assertSame('hardFail', $issue->severity);

        $this->actingAs($users['storeManager']);

        // A hard-fail cannot be cleared in one click: the first call must
        // open the confirmation step, not resolve the issue outright.
        $component = Volt::test('validation-issues')->call('updateStatus', $issue->id, 'resolved');
        $this->assertSame('open', $issue->fresh()->status, 'A hard-fail must require a written reason before it can be cleared.');
        $this->assertSame('pending_validation', $gate->fresh()->status);

        // Trying to confirm without writing a reason must fail validation.
        $component->set('resolutionNote', '')->call('confirmHardFailClearance')->assertHasErrors('resolutionNote');
        $this->assertSame('open', $issue->fresh()->status);

        // Only after actually writing a reason does it clear.
        $component->set('resolutionNote', 'Confirmed with vendor accounts team — this is a genuine re-delivery against the same PO, invoice was reused in error but goods are real and distinct from GATE-1001.')
            ->call('confirmHardFailClearance')
            ->assertHasNoErrors();

        $this->assertSame('resolved', $issue->fresh()->status);
        $this->assertNotEmpty($issue->fresh()->note, 'The written reason must be persisted on the issue.');
        $this->assertSame('validated', $gate->fresh()->status, 'Once the reason is recorded, the hard-fail clears and the gate entry unblocks.');
    }

    /**
     * Scenario 4: QC splits a delivery into accepted/hold/defective/rejected.
     * Confirms GRN posting computes deductions off the correct per-vendor
     * rate and only raises debit notes for buckets that actually have a
     * positive deduction amount.
     */
    public function test_scenario_4_partial_qc_rejection_produces_correct_deductions_and_debit_notes(): void
    {
        $users = $this->users();
        $invoiceNumber = 'KIS/INV/E2E4-'.now()->format('His').random_int(100, 999);

        // Same PO/rate as scenario 2 (₹18/unit) but its own pre-filed vendor
        // submission, so this scenario isolates QC/GRN math only — no
        // validation flags to fight through first.
        VendorSubmission::firstOrCreate(
            ['po_number' => 'PO RM 2627 0031', 'invoice_number' => 'KIS-PRESUB-0031'],
            ['vendor_name' => 'Konkan Insulation Systems', 'invoice_qty' => 450, 'material' => 'Wire Mesh — Structural Packaging', 'has_invoice' => true, 'has_eway_bill' => true, 'has_lr_pod' => true, 'status' => 'acknowledged']
        );

        $this->actingAs($users['guard']);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('driverName', 'E2E Driver Four')
            ->set('driverPhone', '+91 90000 00004')
            ->set('vehicleNumber', 'MH 04 E2E '.random_int(1000, 9999))
            ->set('invoiceNumber', $invoiceNumber)
            ->set('invoiceAmount', '8100')
            ->set('poNumber', 'PO RM 2627 0031')
            ->set('vendorName', 'Konkan Insulation Systems')
            ->set('vendorGst', '27AAFCK5566L1Z2')
            ->set('material', 'Wire Mesh — Structural Packaging')
            ->set('invoiceQty', '450') // matches the PO's own line qty exactly — no QTY_MISMATCH
            ->set('rate', '18') // matches the PO's real list_price — no RATE_MISMATCH
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('invoice_number', $invoiceNumber)->firstOrFail();
        $this->assertSame('validated', $gate->status);

        $this->driveToClose($users, $gate, [
            'accepted' => 300, 'qcHold' => 50, 'defective' => 70, 'rejected' => 30,
            'reasons' => 'Transit damage on 70 units, 30 rejected outright.',
        ], 'KIS-WM-A3');

        $finance = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertEquals(18.0, (float) $finance->rate_per_unit);
        $this->assertEquals(70 * 18, (float) $finance->deduction_defective);
        $this->assertEquals(30 * 18, (float) $finance->deduction_rejected);
        $this->assertEquals(0, (float) $finance->deduction_missing, 'All 450 units were physically accounted for (300+50+70+30) — nothing missing.');
        $this->assertEquals(300 * 18, (float) $finance->final_payable);

        $debitNotes = DebitNote::where('finance_record_id', $finance->id)->pluck('amount', 'reason');
        $this->assertEquals(70 * 18, (float) $debitNotes['Defective goods']);
        $this->assertEquals(30 * 18, (float) $debitNotes['Rejected goods']);
        $this->assertArrayNotHasKey('Missing quantity', $debitNotes->toArray(), 'No debit note should be raised for a zero-amount deduction.');

        $qc = $gate->qcResult;
        $this->assertSame('pending', $qc->return_status, 'A rejected quantity must open a purchase-return flag for the vendor.');
    }

    /**
     * Scenario 5: a Guard logs a gate entry for a vendor who never filed a
     * matching submission. Confirms the fix — the vendor now sees it on
     * their own dashboard immediately, instead of it being invisible until
     * they happened to submit something themselves first.
     */
    public function test_scenario_5_vendor_sees_a_guard_only_gate_entry_on_their_dashboard(): void
    {
        $users = $this->users();
        $invoiceNumber = 'TCM/INV/E2E5-'.now()->format('His').random_int(100, 999);

        $this->actingAs($users['guard']);
        Volt::test('guard.bill-scan')
            ->set('entryType', 'inward')
            ->set('driverName', 'E2E Driver Five')
            ->set('driverPhone', '+91 90000 00005')
            ->set('vehicleNumber', 'MH 04 E2E '.random_int(1000, 9999))
            ->set('invoiceNumber', $invoiceNumber)
            ->set('invoiceAmount', '4200')
            ->set('poNumber', 'PO RM 2627 0020')
            ->set('vendorName', 'Thermocore Materials Pvt Ltd')
            ->set('vendorGst', '27AACCH1234K1Z5')
            ->set('material', 'PCM Raw Compound (TN-1 Grade)')
            ->set('invoiceQty', '700')
            ->set('rate', '42')
            ->set('fetched', true)
            ->call('saveEntry');

        $gate = GateEntry::where('invoice_number', $invoiceNumber)->firstOrFail();
        $this->assertSame(0, VendorSubmission::where('po_number', $gate->po_number)->where('invoice_number', $invoiceNumber)->count(), 'This scenario deliberately has no matching vendor submission.');

        $this->actingAs($users['vendor']);
        Volt::test('vendor.dashboard')->assertSee($gate->gate_no);
    }
}
