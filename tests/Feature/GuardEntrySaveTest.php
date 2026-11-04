<?php

namespace Tests\Feature;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GuardEntrySaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $compiled = storage_path('framework/test-views/guard-save');
        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config()->set('view.compiled', $compiled);
    }

    public function test_guard_autofill_uses_po_line_when_vendor_submission_has_no_material(): void
    {
        // Keep this workflow test independent of the optional development
        // seeder; the isolated testing database is intentionally unseeded.
        $guard = User::factory()->create(['role' => 'guard']);
        $poNumber = 'PO-GUARD-QA-'.now()->format('His').random_int(100, 999);
        $invoiceNumber = 'INV-GUARD-QA-'.now()->format('His').random_int(100, 999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'subject' => 'Guard fallback QA',
            'vendor_name' => 'Guard Fallback Vendor',
            'status' => 'Approved',
            'po_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $po->lines()->create([
            'product' => 'Guard Fallback SKU',
            'quantity' => 10,
            'uom' => 'PCS',
            'list_price' => 10,
        ]);
        $submission = VendorSubmission::create([
            'po_number' => $poNumber,
            'vendor_name' => 'Guard Fallback Vendor',
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->toDateString(),
            'invoice_qty' => 4,
            'invoice_amount' => 40,
            'material' => null,
            'status' => 'submitted',
        ]);
        $gate = null;

        try {
            $this->actingAs($guard);

            Volt::test('guard.bill-scan')
                ->set('entryType', 'inward')
                ->set('invoiceNumber', $invoiceNumber)
                ->call('fetchBillDetails')
                ->assertSet('material', 'Guard Fallback SKU')
                ->set('driverName', 'Guard QA Driver')
                ->set('driverPhone', '+91 90000 00009')
                ->set('vehicleNumber', 'MH 04 QA 9009')
                ->call('saveEntry')
                ->assertHasNoErrors();

            $gate = GateEntry::where('invoice_number', $invoiceNumber)->firstOrFail();
            $this->assertSame('Guard Fallback SKU', $gate->material);
            $this->assertSame('pending_validation', $gate->status);
        } finally {
            if ($gate) {
                ValidationIssue::where('gate_entry_id', $gate->id)->delete();
                $gate->delete();
            }
            $submission->delete();
            $po->delete();
        }
    }
}
