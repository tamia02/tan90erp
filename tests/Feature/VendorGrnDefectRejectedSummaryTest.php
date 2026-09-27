<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VendorGrnDefectRejectedSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_grn_report_shows_read_only_defective_and_rejected_sku_totals_next_to_cn_dn_upload(): void
    {
        $vendor = User::factory()->create([
            'name' => 'GRN Test Vendor',
            'email' => 'grn-test@tan90.demo',
            'role' => Role::Vendor,
        ]);
        $gate = GateEntry::factory()->create([
            'vendor_name' => 'GRN Test Vendor',
            'invoice_number' => 'TEST-CNDN-01',
            'status' => 'grn_posted',
        ]);
        $grn = GrnRecord::create([
            'gate_entry_id' => $gate->id,
            'sku' => 'MULTI-SKU',
            'po_qty' => 1100,
            'invoice_qty' => 1100,
            'physical_received' => 1100,
            'accepted_qty' => 1050,
            'qc_hold_qty' => 0,
            'defective_qty' => 20,
            'rejected_qty' => 30,
            'missing_qty' => 0,
            'suggested_bin' => 'TEST-BIN',
            'posted' => true,
        ]);
        $grn->lines()->createMany([
            ['gate_entry_id' => $gate->id, 'sku' => 'Chemical', 'po_qty' => 500, 'invoice_qty' => 500, 'physical_received' => 500, 'accepted_qty' => 480, 'qc_hold_qty' => 0, 'defective_qty' => 20, 'rejected_qty' => 0, 'missing_qty' => 0],
            ['gate_entry_id' => $gate->id, 'sku' => 'Boxes', 'po_qty' => 600, 'invoice_qty' => 600, 'physical_received' => 600, 'accepted_qty' => 570, 'qc_hold_qty' => 0, 'defective_qty' => 0, 'rejected_qty' => 30, 'missing_qty' => 0],
        ]);

        $this->actingAs($vendor);
        Volt::test('vendor.grn-report', ['gate' => $gate])
            ->assertSee('Defective Qty')
            ->assertSee('Rejected Qty')
            ->assertSee('20')
            ->assertSee('30')
            ->assertSee('Credit note / debit note document');
    }
}
