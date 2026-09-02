<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\User;
use App\Services\GrnPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ThreeWayMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_grn_posting_flags_a_match_when_gate_entry_reconciles_with_the_po(): void
    {
        $po = PurchaseOrder::create(['po_number' => 'PO-TEST-001', 'vendor_name' => 'Acme Vendor']);
        $po->lines()->create(['product' => 'Widget', 'quantity' => 100, 'list_price' => 10]);

        $gate = GateEntry::factory()->create([
            'po_number' => 'PO-TEST-001', 'vendor_name' => 'Acme Vendor',
            'rate' => 10, 'invoice_qty' => 100, 'invoice_amount' => 1000, 'status' => 'qc_done',
        ]);
        QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Widget', 'po_qty' => 100, 'invoice_qty' => 100,
            'physical_received' => 100, 'accepted_qty' => 100, 'qc_hold_qty' => 0,
            'defective_qty' => 0, 'rejected_qty' => 0, 'missing_qty' => 0,
        ]);

        app(GrnPostingService::class)->post($gate, 'BIN-A1');

        $record = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertSame('matched', $record->match_status);
    }

    public function test_grn_posting_flags_an_exception_when_invoice_rate_differs_from_po(): void
    {
        $po = PurchaseOrder::create(['po_number' => 'PO-TEST-002', 'vendor_name' => 'Acme Vendor']);
        $po->lines()->create(['product' => 'Widget', 'quantity' => 100, 'list_price' => 10]);

        $gate = GateEntry::factory()->create([
            'po_number' => 'PO-TEST-002', 'vendor_name' => 'Acme Vendor',
            'rate' => 15, 'invoice_qty' => 100, 'status' => 'qc_done',
        ]);
        QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Widget', 'po_qty' => 100, 'invoice_qty' => 100,
            'physical_received' => 100, 'accepted_qty' => 100, 'qc_hold_qty' => 0,
            'defective_qty' => 0, 'rejected_qty' => 0, 'missing_qty' => 0,
        ]);

        app(GrnPostingService::class)->post($gate, 'BIN-A1');

        $record = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertSame('exception', $record->match_status);
        $this->assertStringContainsString('Invoice rate', $record->match_notes);
    }

    public function test_a_deduction_automatically_issues_a_debit_note(): void
    {
        $gate = GateEntry::factory()->create(['status' => 'qc_done']);
        QcResult::create([
            'gate_entry_id' => $gate->id, 'sku' => 'Widget', 'po_qty' => 100, 'invoice_qty' => 100,
            'physical_received' => 95, 'accepted_qty' => 90, 'qc_hold_qty' => 0,
            'defective_qty' => 5, 'rejected_qty' => 0, 'missing_qty' => 5,
        ]);

        app(GrnPostingService::class)->post($gate, 'BIN-A1');

        $record = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertSame(2, $record->debitNotes()->count());
        $this->assertDatabaseHas('debit_notes', ['finance_record_id' => $record->id, 'reason' => 'Defective goods', 'amount' => 5 * 42]);
        $this->assertDatabaseHas('debit_notes', ['finance_record_id' => $record->id, 'reason' => 'Missing quantity', 'amount' => 5 * 42]);
    }

    public function test_an_unmatched_invoice_cannot_be_cleared_for_payment(): void
    {
        $finance = User::factory()->create(['role' => \App\Enums\Role::Finance]);
        $record = FinanceRecord::create([
            'gate_entry_id' => GateEntry::factory()->create()->id, 'vendor_name' => 'Acme Vendor',
            'rate_per_unit' => 10, 'invoice_value' => 1000, 'accepted_value' => 1000, 'final_payable' => 1000,
            'match_status' => 'exception', 'vendor_status' => 'pending',
        ]);

        $this->actingAs($finance);

        Volt::test('finance.review')
            ->call('setStatus', $record->id, 'cleared')
            ->assertHasErrors('match');

        $this->assertSame('pending', $record->fresh()->vendor_status);
    }
}
