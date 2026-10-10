<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\HtmlToXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;
use ZipArchive;

// 2026-10-10: Guard "Vehicles Inside (Truck Out)" screen, editing a saved PO's
// billing address so Release to vendor works, real .xlsx downloads.
class GuardTruckOutPoAddressExcelTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role])->fresh();
    }

    public function test_guard_sees_vehicles_inside_and_can_truck_out_an_inward_truck(): void
    {
        $guard = $this->user(Role::Guard);
        $inward = GateEntry::factory()->create(['entry_type' => 'inward', 'status' => 'validated', 'vehicle_number' => 'MH04AB1234', 'exited_at' => null]);
        $outward = GateEntry::factory()->create(['entry_type' => 'outward', 'status' => 'dock_assigned', 'vehicle_number' => 'MH04XY9999', 'exited_at' => null]);

        $this->actingAs($guard)->get(route('guard.inside'))->assertOk()
            ->assertSee('MH04AB1234')->assertSee('MH04XY9999')->assertSee('Out opens after loading');
        $this->get(route('guard.dashboard'))->assertSee('Truck Out (2 inside)');

        Volt::test('guard.inside')->call('inwardOut', $inward->id)->assertHasNoErrors();
        $inward->refresh();
        $this->assertNotNull($inward->exited_at);
        $this->assertSame('validated', $inward->status, 'Truck Out must not move the inward flow');
        $this->get(route('guard.inside'))->assertDontSee('MH04AB1234');

        // Outward that is not loaded yet cannot leave (stock is not dispatched).
        Volt::test('guard.inside')->call('outwardOut', $outward->id)->assertHasErrors('exit.'.$outward->id);
        $this->assertNull($outward->fresh()->exited_at);
    }

    public function test_release_without_address_opens_the_address_box_and_save_releases(): void
    {
        $this->actingAs($this->user(Role::StoreManager));
        $po = PurchaseOrder::factory()->create([
            'released_at' => null, 'status' => 'Created',
            'billing_building' => null, 'billing_street' => null, 'billing_city' => null, 'billing_state' => null, 'billing_zip' => null,
        ]);

        $page = Volt::test('admin.po-master')->call('releasePo', $po->id)
            ->assertSet('addressPoId', $po->id)->assertSee('Save &amp; release to vendor', false);
        $this->assertNull($po->fresh()->released_at);

        $page->set('addressForm.building', 'Unit 4')->set('addressForm.street', 'MIDC Road')
            ->set('addressForm.city', 'Thane')->set('addressForm.state', 'Maharashtra')->set('addressForm.zip', '400604')
            ->call('saveAddress', true)->assertHasNoErrors();

        $po->refresh();
        $this->assertSame('Thane', $po->billing_city);
        $this->assertNotNull($po->released_at);
    }

    public function test_po_excel_is_a_real_xlsx_workbook(): void
    {
        $this->actingAs($this->user(Role::StoreManager));
        $po = PurchaseOrder::factory()->create(['po_number' => 'PORM26270777']);

        $response = $this->get(route('admin.po.excel', $po->po_number))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('PORM26270777.xlsx', $response->headers->get('content-disposition'));

        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $this->assertStringContainsString('PORM26270777', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($file);
    }

    public function test_html_tables_become_rows(): void
    {
        $rows = (new HtmlToXlsx)->rowsFromHtml('<h1>Title</h1><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>x &amp; y</td></tr></table>');
        $this->assertSame([['Title'], ['A', 'B'], ['1', 'x & y']], $rows);
    }
}
