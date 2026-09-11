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
 * Reproduces the reported bug: once a gate entry moved through QC/GRN/
 * Finance, the Guard's own "View Activity" page showed all of it — GRN
 * accepted/rejected quantities, finance deductions, payable amounts — none
 * of which is a guard's business. This data is scoped to a guard's own
 * dedicated entry-detail page (guard.entries.show, gated by role:guard),
 * so the fix removes those sections from it entirely rather than adding a
 * role check for a role that's already the only one who can reach the page.
 */
class GuardEntryDetailVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_does_not_see_qc_grn_or_finance_details_on_a_closed_entry(): void
    {
        $guard = User::factory()->create(['role' => 'guard']);
        $gate = GateEntry::factory()->create(['status' => 'closed', 'gate_no' => 'GATE-VIS-1']);

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
            'gate_entry_id' => $gate->id, 'invoice_number' => 'FIN-VIS-1', 'vendor_name' => 'Test Vendor',
            'rate_per_unit' => 999.99, 'invoice_value' => 99999, 'accepted_value' => 89999,
            'final_payable' => 89999, 'vendor_status' => 'cleared',
        ]);

        $this->actingAs($guard);

        $component = Volt::test('guard.entry-detail', ['entry' => $gate])
            ->assertOk();

        $html = $component->html();
        $this->assertStringNotContainsString('999.99', $html);
        $this->assertStringNotContainsString('89999', $html);
        $this->assertStringNotContainsString('QC result', $html);
        $this->assertStringNotContainsString('GRN record', $html);
        $this->assertStringNotContainsString('Finance record', $html);
        $this->assertStringContainsString('GATE-VIS-1', $html);
    }
}
