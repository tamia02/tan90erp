<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\VehicleType;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Transporter;
use App\Models\User;
use App\Models\ZohoSalesDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticsPhaseOneTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    private Location $site;

    private Transporter $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['role' => Role::Admin, 'super_admin' => true, 'access_mode' => 'advanced', 'is_active' => true]);
        $this->site = Location::create(['code' => 'RTD-'.Str::random(8), 'name' => 'Phase One Plant', 'type' => 'Manufacturing', 'state' => 'Tamil Nadu', 'city' => 'Chennai', 'status' => 'active', 'approval_status' => 'approved']);
        $this->carrier = $this->carrier('First carrier');
        $this->actingAs($this->actor);
    }

    private function carrier(string $name): Transporter
    {
        return Transporter::create(['code' => 'C-'.Str::random(10), 'name' => $name, 'status' => 'active', 'approval_status' => 'approved']);
    }

    private function intake(array $overrides = []): array
    {
        return array_replace(['creation_key' => (string) Str::uuid(), 'site_id' => $this->site->id, 'channel' => 'inland', 'source_kind' => 'Sales', 'reference_number' => 'INV-RTD-01', 'provider_name' => 'Plant provider', 'source_location' => 'Chennai', 'vertical' => 'Cold chain', 'purpose' => 'Customer dispatch', 'destination' => 'Bengaluru delivery point', 'items' => [['name' => 'PCM pack', 'quantity' => 10, 'unit' => 'NOS']], 'weight_kg' => 18.25, 'priority' => 'high', 'currency' => 'INR', 'revenue' => '5000.00', 'rtd_at' => now()->subHours(4)->toDateTimeString(), 'rtd_confirmed' => '1'], $overrides);
    }

    private function receive(array $input): Shipment
    {
        $this->post(route('logistics.store'), $input)->assertSessionHasNoErrors()->assertRedirect();

        return Shipment::where('creation_key', $input['creation_key'])->firstOrFail();
    }

    private function action(Shipment $s, string $action, array $data): Shipment
    {
        $this->post(route('logistics.action', [$s, $action]), $data + ['version' => $s->version])->assertSessionHasNoErrors()->assertRedirect(route('logistics.show', $s));

        return $s->fresh();
    }

    public function test_inland_rtd_to_delivered_with_bids_revised_edd_costs_and_private_pod(): void
    {
        Storage::fake('local');
        $beforeStock = DB::table('inventory_movements')->count();
        $input = $this->intake(['source_kind' => 'TO', 'reference_number' => 'TO-RTD-01']);
        $s = $this->receive($input);
        $this->post(route('logistics.store'), $input)->assertRedirect(route('logistics.show', $s));
        $this->assertSame(1, Shipment::where('creation_key', $input['creation_key'])->count());
        $this->get(route('logistics.index'))->assertOk()->assertSee('TO-RTD-01');
        $this->get(route('logistics.show', $s))->assertOk()->assertSee('Assign to Logistics');
        $other = $this->carrier('Second carrier');
        $s = $this->action($s, 'quote', ['transporter_id' => $this->carrier->id, 'amount' => '1000.00', 'vendor_rank' => 1]);
        $s = $this->action($s, 'quote', ['transporter_id' => $other->id, 'amount' => '1100.00', 'vendor_rank' => 2]);
        $vehicle = VehicleType::create(['name' => 'Truck '.Str::random(8)]);
        $plan = ['transport_mode' => 'FTL', 'transporter_id' => $other->id, 'vehicle_type_id' => $vehicle->id, 'vehicle_number' => 'TN00TEST01', 'driver_name' => 'Test driver', 'driver_phone' => '9999900000', 'expected_delivery_at' => now()->addDay()->toDateTimeString()];
        $this->post(route('logistics.action', [$s, 'plan']), $plan + ['version' => $s->version])->assertSessionHasErrors('selection_reason');
        $s = $this->action($s, 'plan', $plan + ['selection_reason' => 'Vehicle available for the loading window.']);
        $this->assertSame('1100.00', $s->base_freight);
        $this->post(route('logistics.action', [$s, 'transition']), ['version' => $s->version, 'to_status' => 'delivered', 'actual_at' => now()->subHour()->toDateTimeString()])->assertSessionHasErrors('status');
        $s = $this->action($s, 'charge', ['type' => 'loading', 'amount' => '50.00', 'remarks' => 'Loading crew']);
        $s = $this->action($s, 'transition', ['to_status' => 'in_transit', 'actual_at' => now()->subHours(3)->toDateTimeString()]);
        $s = $this->action($s, 'edd', ['expected_delivery_at' => now()->addDays(2)->toDateTimeString(), 'reason' => 'Traffic diversion on planned route']);
        $this->assertSame(1, $s->revisions()->count());
        $s = $this->action($s, 'transition', ['to_status' => 'at_location', 'actual_at' => now()->subHours(2)->toDateTimeString()]);
        $s = $this->action($s, 'charge', ['type' => 'halting', 'amount' => '25.00', 'remarks' => 'Waiting at site']);
        $s = $this->action($s, 'charge', ['type' => 'unloading', 'amount' => '75.00', 'remarks' => 'Unloading crew']);
        $s = $this->action($s, 'transition', ['to_status' => 'delivered', 'actual_at' => now()->subHour()->toDateTimeString()]);
        $this->assertSame('delivered', $s->status);
        $this->assertSame('1250.00', $s->totalCost());
        $this->assertFalse($s->attachments()->where('type', 'pod')->exists());
        $s = $this->action($s, 'attachment', ['type' => 'pod', 'file' => UploadedFile::fake()->create('delivery-proof.pdf', 40, 'application/pdf')]);
        $attachment = $s->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->get(route('logistics.attachments.download', $attachment))->assertOk()->assertDownload('delivery-proof.pdf');
        $s = $this->action($s, 'completion', ['poc_name' => 'Receiver', 'poc_phone' => '9999900001', 'google_maps_url' => 'https://maps.google.com/?q=Bengaluru', 'revenue' => '5000.00']);
        $this->get(route('logistics.show', $s))->assertOk()->assertSee('Traffic diversion')->assertSee('delivery-proof.pdf')->assertSee('25.00%');
        $this->assertDatabaseCount('inventory_movements', $beforeStock);
    }

    public function test_d2c_loads_a_zoho_order_and_delivers_without_a_truck_or_pod(): void
    {
        config(['services.zoho_items_pull' => ['organization_id' => 'phase-one-test', 'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'token', 'accounts_url' => 'https://accounts.zoho.test', 'api_base_url' => 'https://zoho.test/inventory/v1'], 'services.zoho.inventory.sync_enabled' => true, 'services.zoho.inventory.rate_limit.per_minute' => 0]);
        $doc = ZohoSalesDocument::create(['organization_id' => 'phase-one-test', 'type' => 'salesorder', 'zoho_id' => '2001', 'number' => 'SO-COURIER', 'customer_name' => 'Courier customer', 'payload' => [], 'synced_at' => now()]);
        Http::fake(['accounts.zoho.test/*' => Http::response(['access_token' => 'fake']), 'zoho.test/inventory/v1/salesorders/2001*' => Http::response(['code' => 0, 'salesorder' => ['salesorder_id' => '2001', 'currency_code' => 'INR', 'shipping_address' => ['city' => 'Chennai'], 'line_items' => [['name' => 'PCM pack', 'quantity' => 2, 'unit' => 'NOS']]]])]);
        $this->post(route('logistics.documents.refresh', $doc))->assertRedirect(route('logistics.create', ['document' => $doc->id]));
        $this->get(route('logistics.create', ['document' => $doc->id]))->assertOk()->assertViewHas('items', [['name' => 'PCM pack', 'quantity' => 2, 'unit' => 'NOS']]);
        $s = $this->receive($this->intake(['channel' => 'd2c', 'zoho_sales_document_id' => $doc->id]));
        $this->assertSame('SO-COURIER', $s->reference_number);
        $plan = ['transporter_id' => $this->carrier->id, 'base_freight' => '99.50', 'expected_delivery_at' => now()->addDay()->toDateTimeString()];
        $this->post(route('logistics.action', [$s, 'plan']), $plan + ['transport_mode' => 'FTL', 'version' => $s->version])->assertSessionHasErrors('transport_mode');
        $s = $this->action($s, 'plan', $plan + ['transport_mode' => 'Express']);
        foreach (['in_transit' => 3, 'at_location' => 2, 'delivered' => 1] as $state => $hours) {
            $s = $this->action($s, 'transition', ['to_status' => $state, 'actual_at' => now()->subHours($hours)->toDateTimeString()]);
        }
        $this->assertSame('delivered', $s->status);
        $this->assertNull($s->vehicle_type_id);
        $this->assertSame('99.50', $s->totalCost());
        $this->assertSame(0, $s->attachments()->count());
        Http::assertSentCount(2);
    }

    public function test_stale_submission_and_unauthorized_roles_cannot_mutate_or_read_shipments(): void
    {
        $s = $this->receive($this->intake());
        $s = $this->action($s, 'quote', ['transporter_id' => $this->carrier->id, 'amount' => 100, 'vendor_rank' => 1]);
        $this->post(route('logistics.action', [$s, 'quote']), ['version' => 1, 'transporter_id' => $this->carrier->id, 'amount' => 1, 'vendor_rank' => 1])->assertSessionHasErrors('version');
        $this->assertEquals(100, $s->quotes()->value('amount'));
        $guard = User::factory()->create(['role' => Role::Guard, 'access_mode' => 'legacy', 'is_active' => true]);
        $this->actingAs($guard)->get(route('logistics.show', $s))->assertForbidden();
        $this->post(route('logistics.store'), $this->intake())->assertForbidden();
    }

    public function test_inland_can_link_rtd_invoice_for_dc_to_or_po_without_creating_legacy_orders(): void
    {
        config(['services.zoho_items_pull.organization_id' => 'phase-one-test']);
        $before = DB::table('flow_customer_orders')->count();
        foreach (['DC', 'TO', 'PO'] as $index => $source) {
            $doc = ZohoSalesDocument::create(['organization_id' => 'phase-one-test', 'type' => 'invoice', 'zoho_id' => (string) (3001 + $index), 'number' => $source.'-INVOICE', 'payload' => [], 'synced_at' => now()]);
            $s = $this->receive($this->intake(['source_kind' => $source, 'zoho_sales_document_id' => $doc->id]));
            $this->assertSame($source, $s->source_kind);
            $this->assertSame($doc->number, $s->reference_number);
        }
        $this->post(route('logistics.store'), $this->intake(['channel' => 'd2c', 'source_kind' => 'PO']))->assertSessionHasErrors('source_kind');
        $this->assertDatabaseCount('flow_customer_orders', $before);
    }
}
