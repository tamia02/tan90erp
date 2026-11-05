<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\Tan90\MasterData\NumberSeries;
use App\Models\User;
use App\Models\VendorMaster;
use App\Services\Tan90\MasterData\NumberSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

// Meeting 2026-10-08: PO numbering series per PO category, restarting each
// financial year; Admin edits them in Master Data > Number Series.
class PoNumberSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_financial_year_code_and_april_reset(): void
    {
        $this->assertSame('2627', NumberSeriesService::financialYear(Carbon::create(2026, 10, 9)));
        $this->assertSame('2526', NumberSeriesService::financialYear(Carbon::create(2026, 3, 31)));

        $series = app(NumberSeriesService::class);
        Carbon::setTestNow('2027-03-30');
        $this->assertSame('PO RM 2627 0001', $series->next('Purchase Order: Raw Material'));
        $this->assertSame('PO RM 2627 0002', $series->next('Purchase Order: Raw Material'));
        $this->assertSame('PO TR 2627 0001', $series->next('Purchase Order: Trading'));

        Carbon::setTestNow('2027-04-01');
        $this->assertSame('PO RM 2728 0001', $series->peek('Purchase Order: Raw Material'));
        $this->assertSame('PO RM 2728 0001', $series->next('Purchase Order: Raw Material'));
    }

    public function test_series_without_a_period_yet_keeps_counting(): void
    {
        NumberSeries::create(['module' => 'Legacy Yearly', 'pattern' => 'LE-{####}', 'next_number' => 7, 'reset_policy' => 'Yearly', 'status' => 'active']);

        $this->assertSame('LE-0007', app(NumberSeriesService::class)->next('Legacy Yearly'));
    }

    public function test_blank_po_number_takes_the_next_number_of_its_category(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]));
        $vendor = VendorMaster::create(['vendor_name' => 'Series Vendor', 'gst_number' => '27AABCP1234Q1Z5', 'contact_phone' => '1', 'category' => 'Raw Material', 'active' => true]);
        SkuMaster::create(['sku' => 'Series RM', 'category' => 'PCM — Raw Material', 'unit' => 'KG']);
        $fy = NumberSeriesService::financialYear();
        // 2026-10-10: Zoho format POxx{FY}{####}, continuing after the highest existing one.
        PurchaseOrder::factory()->create(['po_number' => "PO RM {$fy} 0001"]); // old spaced ERP number
        PurchaseOrder::factory()->create(['po_number' => "PORM{$fy}0063"]);   // pulled from Zoho

        $make = function (string $poNumber, string $nature = 'Raw Material') use ($vendor) {
            $page = Volt::test('admin.po-master')->set('purchaseNature', $nature)->call('selectVendor', $vendor->vendor_name);
            if ($poNumber !== 'auto') {
                $page->set('poNumber', $poNumber);
            }

            return $page->set('billingBuilding', 'Office')->set('billingStreet', 'Road')->set('billingCity', 'Thane')
                ->set('billingState', 'Maharashtra')->set('billingZip', '400604')
                ->set('items.0.product', 'Series RM')->set('items.0.hsnSac', '3824')
                ->set('items.0.quantity', '10')->set('items.0.uom', 'KG')->set('items.0.listPrice', '40')
                ->call('saveAndSubmit')->assertHasNoErrors();
        };

        // Choosing the type fills the number in the form straight away.
        Volt::test('admin.po-master')->set('purchaseNature', 'Trading')->assertSet('poNumber', "POTG{$fy}0001")->assertSet('poNumberAuto', true);
        Volt::test('admin.po-master')->set('purchaseNature', 'Raw Material')->assertSet('poNumber', "PORM{$fy}0064");

        $make('auto');
        $this->assertTrue(PurchaseOrder::where('po_number', "PORM{$fy}0064")->exists());
        $make('');
        $this->assertTrue(PurchaseOrder::where('po_number', "PORM{$fy}0065")->exists()); // next one in order
        $make('auto', 'Trading');
        $this->assertTrue(PurchaseOrder::where('po_number', "POTG{$fy}0001")->exists());
        $make('MANUAL-77');
        $this->assertTrue(PurchaseOrder::where('po_number', 'MANUAL-77')->exists());
    }
}
