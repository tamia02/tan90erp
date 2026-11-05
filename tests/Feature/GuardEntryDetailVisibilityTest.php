<?php

namespace Tests\Feature;

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\QcResult;
use App\Models\User;
use App\Models\ValidationIssue;
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

        ValidationIssue::create([
            'gate_entry_id' => $gate->id,
            'code' => 'SKU_NOT_MAPPED',
            'title' => 'SKU Not Mapped',
            'description' => 'This issue was reviewed before the entry was closed.',
            'severity' => 'hardFail',
            'status' => 'approved',
        ]);

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
        $this->assertStringNotContainsString('accepted_qty', $html);
        $this->assertStringNotContainsString('suggested_bin', $html);
        $this->assertStringContainsString('GATE-VIS-1', $html);
        $this->assertStringContainsString('Current entry status: Closed.', $html);
        $this->assertStringContainsString('SKU Not Mapped', $html);
        $this->assertStringNotContainsString('held at "Pending Validation"', $html);
    }

    public function test_visitor_status_is_not_reported_as_waiting_for_a_dock_and_refreshes_live(): void
    {
        $guard = User::factory()->create(['role' => 'guard']);
        $gate = GateEntry::factory()->create([
            'entry_type' => 'visitor',
            'status' => 'checked_in',
            'dock_assigned_at' => null,
            'visitor_checked_in_at' => now(),
            'po_number' => 'VISITOR-TEST-28',
        ]);

        $this->actingAs($guard);

        $component = Volt::test('guard.entry-detail', ['entry' => $gate])
            ->assertOk()
            ->assertSee('Visitor checked in')
            ->assertDontSee('Gate pass pending · dock not assigned')
            ->assertDontSee('PO reference:')
            ->assertDontSee('VISITOR-TEST-28')
            ->assertSee('Visitor status: Visitor checked in');

        // Simulate another role updating the same record while this browser
        // remains on the Guard detail page; a Livewire refresh must reload it.
        $gate->update(['status' => 'closed', 'visitor_checked_out_at' => now()]);

        $component->call('$refresh')
            ->assertSee('Visitor checked out · pass complete');
    }
}
