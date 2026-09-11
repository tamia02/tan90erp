<?php

namespace Tests\Feature;

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\QcResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Test SKU',
            'po_qty' => 100, 'invoice_qty' => 100, 'physical_received' => 100,
            'accepted_qty' => 90, 'rejected_qty' => 10, 'qc_hold_qty' => 0,
        ]);
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
}
