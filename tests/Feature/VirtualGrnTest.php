<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-13 client meeting: Virtual GRN for trading goods shipped by the vendor
// straight to the customer - stock in (GRN) and out (dispatch) in one step.
class VirtualGrnTest extends TestCase
{
    use RefreshDatabase;

    private function po(string $nature): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['purchase_nature' => $nature, 'released_at' => now(), 'status' => 'Approved', 'vendor_name' => 'Cool Traders']);
        $po->lines()->create(['product' => 'Resale Ice Box', 'quantity' => 20, 'uom' => 'PCS', 'list_price' => 500]);

        return $po->fresh('lines');
    }

    public function test_virtual_grn_books_stock_in_and_out_with_a_full_paper_trail(): void
    {
        $this->actingAs($user = User::factory()->create(['role' => Role::StoreManager]));
        $po = $this->po('Trading');
        $line = $po->lines->first();

        Volt::test('admin.po-master')->assertSee('Virtual GRN')->call('openVirtual', $po->id)
            ->assertSet("virtualLines.{$line->id}", 20.0)
            ->call('saveVirtual')->assertHasErrors('virtualCustomer');

        Volt::test('admin.po-master')->call('openVirtual', $po->id)
            ->set('virtualCustomer', 'Frozen Foods Ltd')->set('virtualInvoice', 'CT-INV-55')->set("virtualLines.{$line->id}", 12)
            ->call('saveVirtual')->assertHasNoErrors()->assertSee('Virtual GRN VGRN-');

        $gate = GateEntry::where('gate_no', 'like', 'VGRN-%')->firstOrFail();
        $this->assertSame(['closed', 'CT-INV-55', 'DIRECT SHIPMENT'], [$gate->status, $gate->invoice_number, $gate->vehicle_number]);
        $this->assertTrue((bool) $gate->grnRecord?->posted);
        $this->assertSame(12.0, (float) LedgerEntry::where('gate_entry_id', $gate->id)->where('bucket', 'available')->sum('qty'), 'stock in through the GRN');
        $this->assertSame(1, \App\Models\FinanceRecord::where('gate_entry_id', $gate->id)->count(), 'vendor bill goes to Finance');

        $item = InventoryItem::where('sku', 'Resale Ice Box')->firstOrFail();
        $this->assertSame(12.0, (float) InventoryMovement::where('item_id', $item->id)->where('type', 'dispatched')->sum('qty'), 'and out to the customer');
        $this->assertSame(0.0, app(InventoryService::class)->available($item), 'never in our warehouse');

        Volt::test('grn.register')->assertSee($gate->gate_no)->assertSee('Virtual GRN');
        $this->actingAs(User::factory()->create(['role' => Role::Guard]))->get(route('guard.inside'))->assertDontSee($gate->gate_no);
    }

    public function test_only_released_trading_pos(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $po = $this->po('Raw Material');
        Volt::test('admin.po-master')->call('openVirtual', $po->id)
            ->set('virtualCustomer', 'X')->set('virtualInvoice', 'RM-1')->call('saveVirtual')->assertHasErrors('virtual');
        $this->assertSame(0, GateEntry::where('gate_no', 'like', 'VGRN-%')->count());
    }
}
