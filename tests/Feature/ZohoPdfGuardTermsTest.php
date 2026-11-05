<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\Inventory\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\TermsTemplate;
use App\Models\User;
use App\Models\VendorMaster;
use App\Services\Inventory\InventoryService;
use App\Services\Outward\OutwardShipmentService;
use App\Services\PurchaseOrderDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

// 2026-10-10 batch: Zoho-style PO PDF with QR, Guard outward Truck In / Truck
// Out with "Meeting whom", who created / updated records, Terms & Notes.
class ZohoPdfGuardTermsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role, ...$extra])->fresh();
    }

    public function test_po_pdf_is_in_zoho_format_with_qr_notes_and_terms(): void
    {
        $po = PurchaseOrder::factory()->create([
            'po_number' => 'PORM26270099', 'description' => 'Deliver by Friday', 'terms_and_conditions' => 'Payment within 30 days',
        ]);
        $data = app(PurchaseOrderDocumentService::class)->data($po);
        $html = view('pdf.purchase-order', $data)->render();

        $this->assertStringContainsString('Purchase Order', $html);
        $this->assertStringContainsString('PORM26270099', $html);
        $this->assertStringContainsString('Scan to open this PO', $html);
        $this->assertStringContainsString('Deliver by Friday', $html);
        $this->assertStringContainsString('Payment within 30 days', $html);
        $this->assertStringContainsString(config('company.gstin'), $html);
        $this->assertStringContainsString('Authorized Signature', $html);
    }

    public function test_qr_link_opens_the_po_for_staff_and_vendor(): void
    {
        $this->actingAs($this->user(Role::StoreManager))->get(route('po.open', 'PORM26270099'))
            ->assertRedirect(route('po.tracker', ['po' => 'PORM26270099']));
        $this->actingAs($this->user(Role::Vendor))->get(route('po.open', 'PORM26270099'))
            ->assertRedirect('/vendor/purchase-orders?po=PORM26270099');
        auth()->logout();
        $this->get(route('po.open', 'PORM26270099'))->assertRedirect(route('login'));
    }

    public function test_items_remember_who_created_and_updated_them(): void
    {
        $creator = $this->user(Role::StoreManager, ['name' => 'Creator Person']);
        $editor = $this->user(Role::StoreManager, ['name' => 'Editor Person']);
        $this->actingAs($creator);
        $item = InventoryItem::create(['sku' => 'Who Panel', 'item_type' => 'fg', 'unit' => 'PCS']);
        $this->actingAs($editor);
        $item->update(['unit' => 'NOS']);

        $item->refresh();
        $this->assertSame([$creator->id, $editor->id], [$item->created_by, $item->updated_by]);
        $this->assertSame('Creator Person', $item->createdBy->name);
    }

    public function test_terms_resolve_vendor_own_then_category_then_default(): void
    {
        $default = TermsTemplate::create(['party_type' => 'vendor', 'name' => 'Default', 'terms' => 'Default terms', 'notes' => 'Default notes']);
        $raw = TermsTemplate::create(['party_type' => 'vendor', 'category' => 'Raw Material', 'name' => 'Raw', 'terms' => 'Raw terms']);
        $own = TermsTemplate::create(['party_type' => 'vendor', 'name' => 'Own', 'terms' => 'Own terms']);
        VendorMaster::factory()->create(['vendor_name' => 'Has Own'])->forceFill(['terms_template_id' => $own->id])->saveQuietly();

        $this->assertSame($own->id, TermsTemplate::resolve('vendor', $own->id, 'Raw Material')->id);
        $this->assertSame($raw->id, TermsTemplate::resolve('vendor', null, 'Raw Material')->id);
        $this->assertSame($default->id, TermsTemplate::resolve('vendor', null, 'Something Else')->id);
        $this->assertNull(TermsTemplate::resolve('customer', null, null));
        $raw->update(['active' => false]);
        $this->assertSame($default->id, TermsTemplate::resolve('vendor', null, 'Raw Material')->id);
    }

    public function test_po_form_fills_terms_and_notes_but_keeps_typed_text(): void
    {
        TermsTemplate::create(['party_type' => 'vendor', 'name' => 'Default', 'terms' => 'Default terms', 'notes' => 'Default notes']);
        $vendor = VendorMaster::factory()->create(['vendor_name' => 'Terms Vendor', 'active' => true]);
        $mine = TermsTemplate::create(['party_type' => 'vendor', 'name' => 'Mine', 'terms' => 'Vendor terms', 'notes' => 'Vendor notes']);
        $other = VendorMaster::factory()->create(['vendor_name' => 'Own Terms Vendor', 'active' => true]);
        $other->forceFill(['terms_template_id' => $mine->id])->saveQuietly();

        $this->actingAs($this->user(Role::StoreManager));
        Volt::test('admin.po-master')
            ->call('selectVendor', $vendor->vendor_name)
            ->assertSet('termsAndConditions', 'Default terms')->assertSet('poNotes', 'Default notes')
            ->call('selectVendor', $other->vendor_name)
            ->assertSet('termsAndConditions', 'Vendor terms')->assertSet('poNotes', 'Vendor notes')
            ->set('termsAndConditions', 'Typed by hand')
            ->call('selectVendor', $vendor->vendor_name)
            ->assertSet('termsAndConditions', 'Typed by hand')->assertSet('poNotes', 'Default notes');
    }

    public function test_terms_and_notes_page_saves_and_assigns(): void
    {
        $vendor = VendorMaster::factory()->create(['vendor_name' => 'Assign Me', 'active' => true]);
        $manager = $this->user(Role::StoreManager);
        $this->actingAs($manager);
        Volt::test('admin.terms-templates')
            ->set('category', 'Raw Material')->set('name', 'RM terms')->set('terms', 'RM T&C')->set('notes', 'RM notes')
            ->call('save')->assertHasNoErrors()
            ->set('assignName', 'Assign Me')->call('assign')->assertHasNoErrors()
            ->set('assignName', 'Nobody')->call('assign')->assertHasErrors('assignName');
        $t = TermsTemplate::where('name', 'RM terms')->firstOrFail();
        $this->assertSame(['Raw Material', $manager->id, $t->id], [$t->category, $t->created_by, $vendor->fresh()->terms_template_id]);

        $this->actingAs($this->user(Role::Guard))->get(route('terms-templates.index'))->assertForbidden();
    }

    public function test_guard_truck_in_needs_only_driver_vehicle_and_whom_they_meet(): void
    {
        $contact = $this->user(Role::StoreManager, ['name' => 'Dock Boss', 'phone' => '9876543210']);
        $this->actingAs($this->user(Role::Guard));
        Volt::test('guard.bill-scan')->call('setEntryType', 'outward')
            ->assertSet('outwardMode', 'in')->assertSee('Truck Out')->assertDontSee('Package Number')
            ->set('driverName', 'Ravi')->set('vehicleNumber', 'MH 04 IN 1')
            ->call('saveEntry')->assertHasErrors('contactUserId')
            ->set('contactUserId', (string) $contact->id)->assertSee('Call Dock Boss: 9876543210')
            ->call('saveEntry')->assertHasNoErrors();

        $gate = GateEntry::where('vehicle_number', 'MH 04 IN 1')->firstOrFail();
        $this->assertSame(['outward', 'pending_validation', $contact->id], [$gate->entry_type, $gate->status, $gate->contact_user_id]);

        // Store Manager sees whom the driver is meeting.
        $this->actingAs($contact);
        Volt::test('store-manager.entry-approvals')->assertSee('Meeting Dock Boss');
    }

    public function test_inward_can_record_whom_the_driver_meets(): void
    {
        $contact = $this->user(Role::StoreExec, ['name' => 'Bay Person']);
        $this->actingAs($this->user(Role::Guard));
        Volt::test('guard.bill-scan')
            ->set('driverName', 'D')->set('driverPhone', '9')->set('vehicleNumber', 'MH 04 IW 2')
            ->set('invoiceNumber', 'INV-1')->set('invoiceAmount', '100')->set('poNumber', 'NO-SUCH-PO')
            ->set('vendorName', 'V')->set('material', 'M')->set('contactUserId', (string) $contact->id)
            ->call('saveEntry')->assertHasNoErrors();
        $gate = GateEntry::where('vehicle_number', 'MH 04 IW 2')->firstOrFail();
        $this->assertSame($contact->id, $gate->contact_user_id);
        Volt::test('guard.entry-detail', ['entry' => $gate])->assertSee('Meeting Whom')->assertSee('Bay Person');
    }

    public function test_guard_truck_out_shows_what_leaves_and_exit_dispatches(): void
    {
        $fg = InventoryItem::create(['sku' => 'Truck Out Panel', 'item_type' => 'fg', 'unit' => 'PCS', 'opening_qty' => 40]);
        $shipment = app(OutwardShipmentService::class)->create([
            'purpose' => 'transfer', 'from_name' => 'CHENNAI', 'from_gstin' => '33AAGCT9099K1ZP', 'from_state' => 'Tamil Nadu',
            'to_name' => 'Chennai Plant 2', 'to_gstin' => '33AAGCT9099K1ZP', 'to_state' => 'Tamil Nadu',
        ], [['item_id' => $fg->id, 'qty' => 6, 'rate' => 100]], $this->user(Role::StoreManager));
        $gate = GateEntry::factory()->create(['entry_type' => 'outward', 'status' => 'loaded', 'outward_shipment_id' => $shipment->id, 'vehicle_number' => 'TN 09 OUT 7']);
        $shipment->update(['gate_entry_id' => $gate->id]);
        $waiting = GateEntry::factory()->create(['entry_type' => 'outward', 'status' => 'dock_assigned', 'vehicle_number' => 'TN 09 WAIT 8']);

        $this->actingAs($this->user(Role::Guard));
        Volt::test('guard.bill-scan')->call('setEntryType', 'outward')->call('setOutwardMode', 'out')
            ->assertSee('TN 09 OUT 7')->assertSee($shipment->number)->assertSee('6 PCS')->assertSee('Truck Out Panel')
            ->assertSee('TN 09 WAIT 8')->assertSee('Not loaded yet')
            ->call('confirmTruckExit', $waiting->id)->assertHasErrors('exit.'.$waiting->id)
            ->call('confirmTruckExit', $gate->id)->assertHasNoErrors()->assertSee('stock has been deducted');

        $this->assertSame(['dispatched', 'dispatched', 34.0], [$gate->fresh()->status, $shipment->fresh()->status, app(InventoryService::class)->available($fg->fresh())]);
        $this->assertSame('dock_assigned', $waiting->fresh()->status);
    }
}
