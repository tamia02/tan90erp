<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\User;
use App\Services\GrnPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GrnPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_returns_null_without_a_qc_result(): void
    {
        $gate = GateEntry::factory()->create();

        $grn = app(GrnPostingService::class)->post($gate, 'BHW-PCM-A1');

        $this->assertNull($grn);
    }

    public function test_post_creates_grn_ledger_and_finance_records(): void
    {
        $gate = GateEntry::factory()->create([
            'vendor_name' => 'Thermocore Materials Pvt Ltd',
            'invoice_number' => 'TEST/INV/001',
            'status' => 'qc_done',
            // Deliberately not ₹42 (the old hardcoded placeholder) — proves
            // the payable comes from the gate's own real rate.
            'rate' => 45,
        ]);

        QcResult::create([
            'gate_entry_id' => $gate->id,
            'sku' => 'PCM Raw Compound (TN-1 Grade)',
            'po_qty' => 700,
            'invoice_qty' => 700,
            'physical_received' => 695,
            'accepted_qty' => 690,
            'qc_hold_qty' => 0,
            'defective_qty' => 5,
            'rejected_qty' => 0,
            'missing_qty' => 5,
        ]);

        $grn = app(GrnPostingService::class)->post($gate, 'BHW-PCM-A1');

        $this->assertNotNull($grn);
        $this->assertTrue($grn->posted);
        $this->assertSame('BHW-PCM-A1', $grn->suggested_bin);
        $this->assertNotNull($grn->vendor_notified_at, 'A posted GRN must be visible to the vendor immediately.');

        $this->assertDatabaseHas('ledger_entries', [
            'gate_entry_id' => $gate->id, 'bucket' => 'available', 'qty' => 690,
        ]);
        $this->assertDatabaseHas('ledger_entries', [
            'gate_entry_id' => $gate->id, 'bucket' => 'defective', 'qty' => 5,
        ]);
        $this->assertDatabaseMissing('ledger_entries', [
            'gate_entry_id' => $gate->id, 'bucket' => 'rejected',
        ]);

        $this->assertDatabaseHas('finance_records', [
            'gate_entry_id' => $gate->id,
            'vendor_name' => 'Thermocore Materials Pvt Ltd',
            'final_payable' => 690 * 45,
            'vendor_status' => 'pending',
        ]);

        // GRN posting is complete, but putaway remains a separate Store
        // Executive action. The entry closes only after the bin is confirmed.
        $this->assertSame('grn_posted', $gate->fresh()->status);
    }

    public function test_post_skips_zero_quantity_buckets(): void
    {
        $gate = GateEntry::factory()->create(['status' => 'qc_done']);

        QcResult::create([
            'gate_entry_id' => $gate->id,
            'sku' => 'Test SKU',
            'po_qty' => 100,
            'invoice_qty' => 100,
            'physical_received' => 100,
            'accepted_qty' => 100,
            'qc_hold_qty' => 0,
            'defective_qty' => 0,
            'rejected_qty' => 0,
            'missing_qty' => 0,
        ]);

        app(GrnPostingService::class)->post($gate, 'BHW-PCM-B1');

        $this->assertSame(1, $gate->ledgerEntries()->count());
    }

    public function test_multi_sku_grn_keeps_quantities_prices_values_and_inventory_separate(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-MULTI-GRN-'.now()->format('His'),
            'vendor_name' => 'Two Item Supplier',
            'subject' => 'Chemical and box stock test',
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'released_at' => now(),
        ]);
        $chemical = $po->lines()->create(['product' => 'Chemical', 'quantity' => 500, 'uom' => 'KG', 'list_price' => 14.25]);
        $boxes = $po->lines()->create(['product' => 'Boxes', 'quantity' => 600, 'uom' => 'PCS', 'list_price' => 3.10]);
        $gate = GateEntry::factory()->create([
            'po_number' => $po->po_number,
            'vendor_name' => $po->vendor_name,
            'invoice_number' => 'INV-MULTI-001',
            'invoice_qty' => 1100,
            'invoice_amount' => 8985,
            'product_lines' => [
                ['po_line_id' => $chemical->id, 'sku' => 'Chemical', 'qty' => 500, 'uom' => 'KG'],
                ['po_line_id' => $boxes->id, 'sku' => 'Boxes', 'qty' => 600, 'uom' => 'PCS'],
            ],
        ]);

        app(\App\Services\QcService::class)->recordMultiResult($gate, [
            ['sku' => 'Chemical', 'poQty' => 500, 'invoiceQty' => 500, 'physicalReceived' => 500, 'accepted' => 500, 'qcHold' => 0, 'defective' => 0, 'rejected' => 0, 'invoiceAmount' => 7125, 'unitPrice' => 14.25],
            ['sku' => 'Boxes', 'poQty' => 600, 'invoiceQty' => 600, 'physicalReceived' => 600, 'accepted' => 600, 'qcHold' => 0, 'defective' => 0, 'rejected' => 0, 'invoiceAmount' => 1860, 'unitPrice' => 3.10],
        ], parameters: [
            ['name' => 'Quantity', 'value' => 'Each SKU split independently', 'result' => 'Pass'],
            ['name' => 'Invoice / PO Amount', 'value' => 'Separate line amounts', 'result' => 'Pass'],
            ['name' => 'Unit Price', 'value' => 'Separate line prices', 'result' => 'Pass'],
        ]);

        $this->actingAs($manager);
        Volt::test('grn.check')
            ->assertSee('Multi-SKU receipt: each SKU amount is shown on its own row below. No combined GRN amount is displayed.')
            ->assertSee('Chemical')
            ->assertSee('Boxes')
            ->assertDontSee('Total Amount');

        $grn = app(GrnPostingService::class)->post($gate->fresh(), 'MULTI-STAGE-1');
        $this->assertNotNull($grn);

        $lines = $grn->lines()->orderBy('sku')->get();
        $this->assertSame(['Boxes', 'Chemical'], $lines->pluck('sku')->all());
        $this->assertSame([600, 500], $lines->pluck('accepted_qty')->map(fn ($qty) => (int) $qty)->all());
        $this->assertEquals(3.10, (float) $lines[0]->unit_price);
        $this->assertEquals(14.25, (float) $lines[1]->unit_price);
        $this->assertEquals(1860.00, (float) $lines[0]->invoice_amount);
        $this->assertEquals(7125.00, (float) $lines[1]->invoice_amount);
        $this->assertEquals(1860.00, (float) $lines[0]->accepted_value);
        $this->assertEquals(7125.00, (float) $lines[1]->accepted_value);

        $this->assertDatabaseHas('ledger_entries', ['gate_entry_id' => $gate->id, 'sku' => 'Chemical', 'bucket' => 'available', 'qty' => 500, 'unit_price' => 14.25, 'extended_value' => 7125]);
        $this->assertDatabaseHas('ledger_entries', ['gate_entry_id' => $gate->id, 'sku' => 'Boxes', 'bucket' => 'available', 'qty' => 600, 'unit_price' => 3.10, 'extended_value' => 1860]);
        $this->assertDatabaseMissing('ledger_entries', ['gate_entry_id' => $gate->id, 'sku' => 'MULTI-SKU']);

        $finance = FinanceRecord::where('gate_entry_id', $gate->id)->firstOrFail();
        $this->assertEquals(8985.00, (float) $finance->accepted_value);
        $this->assertEquals(8985.00, (float) $finance->final_payable);
        $this->assertSame('matched', $finance->match_status);

        $gate->load('qcResult');
        $pdfHtml = view('pdf.grn-report', ['gate' => $gate, 'grn' => $grn->load('lines'), 'qc' => $gate->qcResult])->render();
        $excelHtml = view('reports.grn-excel', ['gate' => $gate, 'grn' => $grn, 'qc' => $gate->qcResult])->render();
        foreach ([$pdfHtml, $excelHtml] as $report) {
            $this->assertStringContainsString('Chemical', $report);
            $this->assertStringContainsString('Boxes', $report);
            $this->assertStringContainsString('14.2500', $report);
            $this->assertStringContainsString('3.1000', $report);
            $this->assertStringContainsString('7,125.00', $report);
            $this->assertStringContainsString('1,860.00', $report);
            $this->assertStringNotContainsString('MULTI-SKU</td>', $report);
        }

        Volt::test('grn.register')
            ->assertSee('Chemical')
            ->assertSee('Boxes')
            ->assertDontSee('MULTI-SKU');
        Volt::test('grn.stock-balance')
            ->assertSee('Chemical')
            ->assertSee('Boxes')
            ->assertSee('₹14.2500')
            ->assertSee('₹3.1000')
            ->assertSee('₹7,125.00')
            ->assertSee('₹1,860.00');
    }

    public function test_grn_after_qc_hold_putaway_uses_confirmed_bin_and_closes_entry(): void
    {
        $storeExec = User::factory()->create(['role' => Role::StoreExec]);
        $gate = GateEntry::factory()->create([
            'status' => 'qc_done',
            'vendor_name' => 'Thermocore Materials Pvt Ltd',
            'invoice_qty' => 1000,
        ]);

        $qc = QcResult::create([
            'gate_entry_id' => $gate->id,
            'sku' => 'TEST-QC-HOLD',
            'po_qty' => 1000,
            'invoice_qty' => 1000,
            'physical_received' => 1000,
            'accepted_qty' => 900,
            'qc_hold_qty' => 100,
            'defective_qty' => 0,
            'rejected_qty' => 0,
            'missing_qty' => 0,
            'hold_reason' => 'Awaiting supplier certificate review',
        ]);

        $this->actingAs($storeExec);
        Volt::test('unloading.putaway')
            ->call('startConfirm', $gate->id)
            ->set('finalBin', 'BHW-QC-HOLD-A1')
            ->call('confirmPutaway', $gate->id)
            ->assertHasNoErrors();

        $this->assertSame('qc_done', $gate->fresh()->status);
        $this->assertSame('BHW-QC-HOLD-A1', $gate->fresh()->final_bin);
        $this->assertNotNull($gate->fresh()->putaway_completed_at);
        $this->assertSame(0, $gate->ledgerEntries()->count(), 'Putaway before GRN must not post stock early.');

        $qc->update(['qc_hold_qty' => 0, 'accepted_qty' => 1000]);
        $grn = app(GrnPostingService::class)->post($gate->fresh(), 'SHOULD-NOT-OVERRIDE-CONFIRMED-BIN');

        $this->assertNotNull($grn);
        $this->assertSame('BHW-QC-HOLD-A1', $grn->suggested_bin);
        $this->assertSame('closed', $gate->fresh()->status);
        $this->assertDatabaseHas('ledger_entries', [
            'gate_entry_id' => $gate->id,
            'bin' => 'BHW-QC-HOLD-A1',
            'bucket' => 'available',
            'qty' => 1000,
        ]);
    }
}
