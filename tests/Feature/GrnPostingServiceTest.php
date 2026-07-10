<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\QcResult;
use App\Services\GrnPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'final_payable' => 690 * 42,
            'vendor_status' => 'pending',
        ]);

        $this->assertSame('closed', $gate->fresh()->status);
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
}
