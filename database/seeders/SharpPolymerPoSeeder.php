<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\SkuMaster;
use App\Models\User;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Imports the supplied Sharp Polymer example as a safe, repeatable demo
 * scenario: one PO, one product, four supplier bills, no fake gate receipt.
 * Physical receipt is intentionally left for the real Guard flow.
 */
class SharpPolymerPoSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = 'Sharp Polymer';

        VendorMaster::updateOrCreate(['vendor_name' => $vendor], [
            'gst_number' => '29ABUFS4693L2ZX',
            'contact_phone' => '+91 9845210705',
            'contact_email' => 'sales@sharppolymers.demo',
            'category' => 'Packaging Panels',
            'active' => true,
            'vendor_owner' => 'Suresh N',
            'address_country' => 'India',
            'address_city' => 'Bangalore',
            'address_state' => 'Karnataka',
            'address_zip' => '560099',
            'description' => 'Imported from the supplied POPM26270078 and four supplier bills.',
        ]);

        SkuMaster::updateOrCreate(['sku' => 'TNPM0500BPSP'], [
            'category' => 'Packaging Panels',
            'unit' => 'NOS',
            'default_bin' => 'CHN-PANEL-A1',
            'mapped' => true,
            'product_owner' => 'Priya Admin',
            'product_code' => 'SP-2249',
            'active' => true,
            'vendor_name' => $vendor,
            'manufacturer' => 'Sharp Polymer',
            'unit_price' => 13.50,
            'tax' => 18,
            'taxable' => true,
            'qty_ordered' => 47850,
            'reorder_level' => 1500,
            'description' => '500gm Blue Panel SP-2249, dark blue, 60gm weight.',
        ]);

        User::updateOrCreate(['email' => 'vendor.sharp@tan90.test'], [
            'name' => $vendor,
            'phone' => '+91 9845210705',
            'role' => Role::Vendor,
            'password' => Hash::make('demo123'),
            'email_verified_at' => now(),
            'description' => 'Sharp Polymer vendor portal account',
        ]);

        $po = PurchaseOrder::updateOrCreate(['po_number' => 'POPM26270078'], [
            'po_owner' => 'Priya Admin',
            'subject' => '500gm Blue Panel SP-2249 - Sharp Polymer',
            'vendor_name' => $vendor,
            'po_date' => '2026-08-18',
            // The PO is valid for delivery through 31 Aug; each invoice's
            // payment due date is tracked separately below.
            'due_date' => '2026-08-31',
            'status' => 'Approved',
            'released_at' => '2026-08-18 10:00:00',
            'tax' => 116275.50,
            'terms_and_conditions' => 'Net 45. Delivery 5-10 days from order acknowledgment. Goods subject to inspection on receipt.',
            'description' => 'Supplier PO imported from POPM26270078. Four partial bills are expected and may be received through separate gate entries.',
            'shipping_country' => 'India',
            'shipping_city' => 'Chennai',
            'shipping_state' => 'Tamil Nadu',
            'shipping_zip' => '600056',
        ]);

        $po->lines()->updateOrCreate(['product' => '500gm Blue Panel SP-2249 (TNPM0500BPSP)'], [
            'quantity' => 47850,
            'list_price' => 13.50,
        ]);

        $ack = PurchaseOrderAcknowledgement::updateOrCreate(['po_number' => $po->po_number], [
            'vendor_name' => $vendor,
            'accepted' => true,
            'remarks' => 'Imported PO accepted for four-part dispatch tracking.',
            'acknowledged_at' => '2026-08-18 10:15:00',
            'production_started_at' => '2026-08-18 10:15:00',
        ]);

        $bills = [
            ['number' => 'SP0678/26-27', 'date' => '2026-08-20', 'due' => '2026-10-04', 'qty' => 10230, 'amount' => 162963.90],
            ['number' => 'SP0700/26-27', 'date' => '2026-08-25', 'due' => '2026-10-09', 'qty' => 6765, 'amount' => 107766.00],
            ['number' => 'SP0707/26-27', 'date' => '2026-08-27', 'due' => '2026-10-11', 'qty' => 9735, 'amount' => 155079.00],
            ['number' => 'SP0722/26-27', 'date' => '2026-08-31', 'due' => '2026-10-15', 'qty' => 21120, 'amount' => 336442.00],
        ];

        foreach ($bills as $index => $bill) {
            VendorSubmission::updateOrCreate(
                ['po_number' => $po->po_number, 'invoice_number' => $bill['number']],
                [
                    'vendor_name' => $vendor,
                    'invoice_date' => $bill['date'],
                    'invoice_due_date' => $bill['due'],
                    'invoice_qty' => $bill['qty'],
                    'invoice_amount' => $bill['amount'],
                    'material' => 'TNPM0500BPSP',
                    'has_invoice' => true,
                    'status' => 'submitted',
                    'note' => 'Imported from supplied bill '.$bill['number'].'; upload the source PDF from the vendor portal when available.',
                    'production_started_at' => $ack->production_started_at,
                    'dispatched_at' => $bill['date'].' 09:00:00',
                    'tat_due_at' => $po->due_date?->endOfDay(),
                ]
            );
        }
    }
}
