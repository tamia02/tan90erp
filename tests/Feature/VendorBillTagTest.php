<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-13 client meeting: tag the vendor's bill to the GRN after QC and GRN.
class VendorBillTagTest extends TestCase
{
    use RefreshDatabase;

    private function grn(bool $posted): GrnRecord
    {
        $gate = GateEntry::factory()->create(['invoice_number' => 'VB-INV-9', 'invoice_amount' => 1180]);

        return GrnRecord::create(['gate_entry_id' => $gate->id, 'sku' => 'RM X', 'po_qty' => 10, 'invoice_qty' => 10, 'physical_received' => 10, 'accepted_qty' => 10, 'suggested_bin' => 'A1', 'posted' => $posted]);
    }

    public function test_store_manager_attaches_the_vendor_bill_to_a_posted_grn(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $grn = $this->grn(true);

        $page = Volt::test('grn.register')->assertSee('Attach vendor bill')->call('openBill', $grn->id)
            ->assertSet('billNumber', 'VB-INV-9')->assertSet('billAmount', '1180.00');
        $page->set('billDate', '')->call('saveBill')->assertHasErrors(['billDate', 'billFile']);
        $page->set('billDate', '2026-10-12')->set('billFile', UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf'))
            ->call('saveBill')->assertHasNoErrors()->assertSee('Bill VB-INV-9');

        $grn->refresh();
        $this->assertSame(['VB-INV-9', '2026-10-12', '1180.00'], [$grn->vendor_bill_number, $grn->vendor_bill_date->toDateString(), $grn->vendor_bill_amount]);
        $this->assertNotNull($grn->vendor_bill_path);
        $this->assertNotNull($grn->vendor_bill_tagged_at);
    }

    public function test_bill_waits_for_the_grn_and_finance_can_open_the_file(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $pending = $this->grn(false);
        Volt::test('grn.register')->assertSee('After GRN')->assertDontSee('Attach vendor bill');

        $posted = $this->grn(true);
        \Illuminate\Support\Facades\Storage::put('vendor-bills/t.pdf', '%PDF-1.4 test');
        $posted->update(['vendor_bill_number' => 'B-1', 'vendor_bill_date' => '2026-10-12', 'vendor_bill_path' => 'vendor-bills/t.pdf']);
        $this->actingAs(User::factory()->create(['role' => Role::Finance]))->get(route('grn.vendor-bill', $posted))->assertOk();
        $this->actingAs(User::factory()->create(['role' => Role::Guard]))->get(route('grn.vendor-bill', $posted))->assertForbidden();
        \Illuminate\Support\Facades\Storage::delete('vendor-bills/t.pdf');
    }
}
