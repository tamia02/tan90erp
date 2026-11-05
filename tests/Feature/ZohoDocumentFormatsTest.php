<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\Inventory\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorSubmission;
use App\Services\Outward\OutwardShipmentService;
use App\Services\VendorBillDocumentService;
use App\Support\PdfDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Documents in the client's formats: GRN as Akhtar's sample, Bill / Delivery
// Challan / Transfer Order as the Zoho Books samples, same header and footer.
class ZohoDocumentFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_in_words_and_indian_money_like_zoho(): void
    {
        $this->assertSame('Indian Rupee Two Lakh Seventy-Four Thousand Six Hundred Only', PdfDocument::amountInWords(274600));
        $this->assertSame('Indian Rupee Forty-Two Thousand Eight Hundred Ninety-Six and Eighty Paise Only', PdfDocument::amountInWords(42896.80));
        $this->assertSame('2,74,600.00', PdfDocument::money(274600));
        $this->assertSame('6,656.40', PdfDocument::money(6656.4));
    }

    public function test_grn_follows_the_client_format_with_signatures(): void
    {
        $exec = User::factory()->create(['role' => Role::StoreExec, 'name' => 'Dilip', 'email' => 'dilip@tan90.test']);
        $manager = User::factory()->create(['role' => Role::StoreManager, 'name' => 'Vasu', 'email' => 'vasu@tan90.test']);
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-GRN-FMT']);
        $po->lines()->create(['product' => 'TN-24 Panel', 'quantity' => 2500, 'uom' => 'Units', 'list_price' => 10]);
        $gate = GateEntry::factory()->create(['po_number' => 'PO-GRN-FMT', 'vendor_name' => 'Panel Maker', 'invoice_number' => 'DC-77',
            'store_exec_verified_by' => $exec->id, 'store_exec_verified_at' => now()]);
        $this->actingAs($manager);
        $grn = GrnRecord::create(['gate_entry_id' => $gate->id, 'sku' => 'TN-24 Panel', 'po_qty' => 2500, 'invoice_qty' => 2500, 'physical_received' => 2510,
            'accepted_qty' => 2490, 'rejected_qty' => 6, 'defective_qty' => 4, 'missing_qty' => 0, 'qc_hold_qty' => 0, 'suggested_bin' => 'A-1', 'posted' => true, 'created_by' => $manager->id]);

        $html = view('pdf.grn-report', ['gate' => $gate->fresh(), 'grn' => $grn->fresh()])->render();
        foreach (['Goods Receipt Note', $grn->number(), 'Request No.', $gate->gate_no, 'Qty Sent', 'Good Qty', 'Damaged', 'Excess', 'Short',
            'RECEIVED BY', 'APPROVED BY', 'dilip@tan90.test', 'vasu@tan90.test', 'PO-GRN-FMT', 'DC-77', 'Units', 'System Generated'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertMatchesRegularExpression('/<td>2500<\/td><td>2490<\/td><td>10<\/td><td>10<\/td><td>0<\/td>/', $html); // sent, good, damaged, excess, short
        $this->assertMatchesRegularExpression('#^GRN/\d{4}-\d{2}/\d{6}$#', $grn->number());
        $this->assertStringStartsWith('%PDF', Pdf::loadHTML($html)->output());
    }

    public function test_bill_is_in_zoho_bill_layout_with_po_lines_and_gst(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-BILL-FMT', 'shipping_state' => 'Kerala']);
        $line = $po->lines()->create(['product' => 'Material Handling', 'quantity' => 10, 'uom' => 'Nos', 'list_price' => 3000, 'gst_rate' => 18]);
        $submission = VendorSubmission::factory()->create(['po_number' => 'PO-BILL-FMT', 'invoice_number' => 'HRC/26-27/145', 'invoice_amount' => null,
            'line_quantities' => [$line->id => 10]]);

        $data = app(VendorBillDocumentService::class)->data($submission);
        $this->assertEqualsWithDelta(30000, $data['subTotal'], 0.01);
        $html = view('pdf.vendor-bill', $data)->render();
        foreach (['BILL', 'Bill# HRC/26-27/145', 'Bill From', 'Order Number', 'PO-BILL-FMT', 'Material Handling', '3,000.00', '30,000.00', 'Sub Total', 'Indian Rupee', 'Authorized Signature'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // GST split follows the PO tax rules (company state vs place of supply).
        $this->assertEqualsWithDelta(30000 + array_sum(array_column($data['taxRows'], 'amount')), $data['billTotal'], 0.01);
    }

    public function test_delivery_challan_and_transfer_order_open_as_zoho_pdfs(): void
    {
        $manager = User::factory()->create(['role' => Role::StoreManager])->fresh();
        $fg = InventoryItem::create(['sku' => 'Pink Panel', 'code' => 'PNK-1', 'item_type' => 'fg', 'unit' => 'Pcs', 'opening_qty' => 100]);
        $shipment = app(OutwardShipmentService::class)->create([
            'purpose' => 'transfer', 'from_name' => 'Faridabad', 'from_gstin' => '06AAHCT1080F1ZK', 'from_state' => 'Haryana',
            'to_name' => 'Sonipat', 'to_gstin' => '06AAHCT1080F1ZK', 'to_state' => 'Haryana', 'notes' => 'Movement of own empty panels',
        ], [['item_id' => $fg->id, 'qty' => 50, 'rate' => 35]], $manager);
        $this->assertNotNull($shipment->challan_number);

        $this->actingAs($manager);
        $this->get(route('inventory.outward.challan', $shipment))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('inventory.outward.transfer-order', $shipment))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $shipment->load(['lines.item', 'fromLocation', 'toLocation', 'creator']);
        $challan = view('pdf.outward-document', ['kind' => 'challan', 'shipment' => $shipment])->render();
        foreach (['Delivery Challan# '.$shipment->challan_number, 'Deliver To', 'Movement Type', 'Internal Movement', 'Place Of Supply: Haryana (06)', 'PNK-1', '1,750.00', 'Indian Rupee One Thousand Seven Hundred Fifty Only', 'Own goods. Not for Sale.', 'Movement of own empty panels'] as $text) {
            $this->assertStringContainsString($text, $challan, $text);
        }
        $transfer = view('pdf.outward-document', ['kind' => 'transfer', 'shipment' => $shipment])->render();
        foreach (['TRANSFER ORDER', 'TransferOrder# '.$shipment->number, 'Source Location', 'Destination Location', 'Date of Transfer', 'Created By', $manager->name, 'Reason'] as $text) {
            $this->assertStringContainsString($text, $transfer, $text);
        }

        // A sale has no Transfer Order.
        $sale = app(OutwardShipmentService::class)->create([
            'purpose' => 'sales', 'from_name' => 'Faridabad', 'from_gstin' => '06AAHCT1080F1ZK', 'from_state' => 'Haryana',
            'to_name' => 'Buyer', 'to_gstin' => '29ABCDE1234F1Z5', 'to_state' => 'Karnataka',
        ], [['item_id' => $fg->id, 'qty' => 1, 'rate' => 35]], $manager);
        $this->get(route('inventory.outward.transfer-order', $sale))->assertNotFound();
    }
}
