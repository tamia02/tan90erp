<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Outward\OutwardComplianceService;
use App\Services\Outward\OutwardShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OutwardShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const CHENNAI = '33AAGCT9099K1ZP';

    private const DELHI = '06AAGCT9099K1ZT';

    private User $admin;

    private InventoryItem $fg;

    private InventoryItem $rm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->fg = InventoryItem::create(['sku' => 'PCM Pack Blue', 'item_type' => 'fg', 'unit' => 'NOS', 'opening_qty' => 100]);
        $this->rm = InventoryItem::create(['sku' => 'PCM Compound', 'item_type' => 'rm', 'unit' => 'KG', 'opening_qty' => 100]);
    }

    private function decide(string $purpose, ?string $from, ?string $to, float $value, ?string $fromState = null, ?string $toState = null): array
    {
        return app(OutwardComplianceService::class)->decide($purpose, $from, $to, $fromState, $toState, $value);
    }

    private function flags(array $d): array
    {
        return [$d['document_type'], $d['is_b2b'], $d['requires_irn'], $d['requires_ewb']];
    }

    public function test_sales_b2b_needs_tax_invoice_irn_and_ewb(): void
    {
        $this->assertSame(['tax_invoice', true, true, true], $this->flags($this->decide('sales', self::CHENNAI, '29ABCDE1234F1Z5', 1000)));
    }

    public function test_sales_b2c_needs_ewb_only_above_threshold(): void
    {
        $this->assertSame(['tax_invoice', false, false, false], $this->flags($this->decide('sales', self::CHENNAI, null, 50000)));
        $this->assertSame(['tax_invoice', false, false, true], $this->flags($this->decide('sales', self::CHENNAI, '', 50001)));
    }

    public function test_einvoice_switch_turns_off_irn(): void
    {
        config(['outward.einvoice_applicable' => false]);
        $this->assertSame(['tax_invoice', true, false, true], $this->flags($this->decide('sales', self::CHENNAI, '29ABCDE1234F1Z5', 1000)));
    }

    public function test_transfer_between_different_gstins_is_a_taxable_supply(): void
    {
        // Meeting 2026-10-08: GST-registered branches -> Transfer Order with GST.
        $this->assertSame(['transfer_order', true, true, true], $this->flags($this->decide('transfer', self::CHENNAI, self::DELHI, 1000)));
        $this->assertSame(['tax_invoice', true, true, true], $this->flags($this->decide('job_work', self::CHENNAI, self::DELHI, 1000)));
    }

    public function test_receiver_without_gst_yet_gets_a_delivery_challan(): void
    {
        $this->assertSame(['delivery_challan', false, false, false], $this->flags($this->decide('transfer', self::CHENNAI, null, 1000)));
        $this->assertSame(['delivery_challan', false, false, true], $this->flags($this->decide('transfer', self::CHENNAI, '', 75000)));
        $this->assertSame(['delivery_challan', false, false, true], $this->flags($this->decide('job_work', self::CHENNAI, null, 100, 'Tamil Nadu', 'Karnataka')));
    }

    public function test_same_gstin_is_a_delivery_challan_with_ewb_over_threshold(): void
    {
        $this->assertSame(['delivery_challan', false, false, false], $this->flags($this->decide('transfer', self::CHENNAI, ' 33aagct9099k1zp ', 50000)));
        $this->assertSame(['delivery_challan', false, false, true], $this->flags($this->decide('transfer', self::CHENNAI, self::CHENNAI, 60000)));
    }

    public function test_interstate_job_work_on_challan_needs_ewb_even_when_small(): void
    {
        $this->assertSame(['delivery_challan', false, false, false], $this->flags($this->decide('job_work', self::CHENNAI, self::CHENNAI, 100, 'Tamil Nadu', 'Tamil Nadu')));
        // Same GSTIN can't really differ in state; the state names are the fallback check.
        $service = new OutwardComplianceService;
        $interstate = (new \ReflectionMethod($service, 'interstate'))->invoke($service, null, null, 'Tamil Nadu', 'Karnataka');
        $this->assertTrue($interstate);
    }

    private function create(string $purpose, ?string $toGstin, array $lines, string $toName = 'Cold Chain Foods'): OutwardShipment
    {
        return app(OutwardShipmentService::class)->create([
            'purpose' => $purpose, 'from_name' => 'Chennai', 'from_gstin' => self::CHENNAI, 'from_state' => 'Tamil Nadu',
            'to_name' => $toName, 'to_gstin' => $toGstin, 'to_state' => null,
        ], $lines, $this->admin);
    }

    private function stock(InventoryItem $item): float
    {
        return app(InventoryService::class)->available($item->fresh());
    }

    public function test_b2b_sale_is_blocked_until_all_documents_are_uploaded_and_stock_drops_only_at_dispatch(): void
    {
        Storage::fake('local');
        $service = app(OutwardShipmentService::class);
        $s = $this->create('sales', '29ABCDE1234F1Z5', [['item_id' => $this->fg->id, 'qty' => 10, 'rate' => 250]]);

        $this->assertSame([sprintf('OUT-%05d', $s->id), 'documents_pending', '2500.00', null], [$s->number, $s->status, (string) $s->total_value, $s->challan_number]);
        $this->assertSame(100.0, $this->stock($this->fg));

        try {
            $service->markDispatched($s, $this->admin);
            $this->fail('Dispatch must be blocked while documents are missing.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }

        $service->saveDocuments($s, ['invoice_number' => 'INV-1', 'invoice_date' => '2026-10-08', 'irn' => 'abc123', 'irn_ack_number' => '112233'],
            ['invoice_file' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);
        $this->assertSame('documents_pending', $s->status);

        $service->saveDocuments($s, ['ewb_number' => '331234567890', 'ewb_valid_until' => '2026-10-10'],
            ['ewb_file' => UploadedFile::fake()->create('ewb.pdf', 10, 'application/pdf')]);
        $this->assertSame('ready', $s->status);
        Storage::disk('local')->assertExists($s->ewb_file);

        $service->markDispatched($s, $this->admin);
        $this->assertSame('dispatched', $s->fresh()->status);
        $this->assertSame(90.0, $this->stock($this->fg));
        $this->assertNotNull($s->lines()->first()->dispatch_id);

        $this->expectException(ValidationException::class);
        $service->saveDocuments($s, ['invoice_number' => 'CHANGED'], []);
    }

    public function test_sales_cannot_include_raw_material_but_job_work_can(): void
    {
        try {
            $this->create('sales', null, [['item_id' => $this->rm->id, 'qty' => 1, 'rate' => 1]]);
            $this->fail('RM must not be sold.');
        } catch (ValidationException) {
        }

        $s = $this->create('job_work', self::CHENNAI, [['item_id' => $this->rm->id, 'qty' => 30, 'rate' => 10]], 'Own job unit');
        $this->assertSame(['delivery_challan', 'ready', sprintf('DC-%05d', $s->id)], [$s->document_type, $s->status, $s->challan_number]);
        app(OutwardShipmentService::class)->markDispatched($s, $this->admin);
        $this->assertSame(70.0, $this->stock($this->rm));
    }

    public function test_short_stock_rolls_back_the_whole_shipment(): void
    {
        $s = $this->create('transfer', self::CHENNAI, [
            ['item_id' => $this->fg->id, 'qty' => 5, 'rate' => 1],
            ['item_id' => $this->rm->id, 'qty' => 500, 'rate' => 1],
        ], 'Chennai store 2');

        try {
            app(OutwardShipmentService::class)->markDispatched($s, $this->admin);
            $this->fail('Short stock must block dispatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('in stock', $e->errors()['dispatch'][0]);
        }
        $this->assertSame(['ready', 100.0, 100.0], [$s->fresh()->status, $this->stock($this->fg), $this->stock($this->rm)]);
    }

    public function test_cancel_and_store_manager_access(): void
    {
        $s = $this->create('sales', null, [['item_id' => $this->fg->id, 'qty' => 1, 'rate' => 1]]);
        app(OutwardShipmentService::class)->cancel($s);
        $this->assertSame('cancelled', $s->fresh()->status);

        $this->actingAs(User::factory()->create(['role' => Role::StoreManager]))
            ->get(route('inventory.dispatch'))->assertOk()->assertSee('Outward Shipment');
        $this->actingAs(User::factory()->create(['role' => Role::Guard]))
            ->get(route('inventory.dispatch'))->assertForbidden();
    }
}
