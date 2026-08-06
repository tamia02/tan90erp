<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\UnloadingRecord;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Models\VendorMaster;
use App\Models\VendorStockUpdate;
use App\Models\VendorSubmission;
use Database\Seeders\Access\AccessControlSeeder;
use Database\Seeders\Forge\ForgeAccessSeeder;
use Illuminate\Database\Seeder;

// Ports the React prototype's src/lib/seed.ts — a closed-loop demo (Thermocore
// Materials Pvt Ltd, PO RM 2627 0020) walked fully through Guard -> Validation
// -> Unloading -> GRN -> Ledger -> Finance, plus a handful of in-flight
// records for the other screens. PCM/cold-chain themed — the client's real
// Zoho Products module confirmed this is the actual business, not chemicals.
//
// Every write below is keyed on a natural identifier via updateOrCreate, so
// this seeder is safe to run repeatedly and safe to run against a database
// that already has some of these rows (e.g. production, where the 7 role
// users and one Zoho-sourced PO already exist before this ever runs).
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();
        $this->seedSkuMaster();
        $this->seedVendorMaster();
        $this->seedPurchaseOrders();
        $this->seedGateEntriesAndDownstream();
        $this->seedVendorStockUpdates();

        // Tan90 module merge: house role registry first (reference-only rows
        // for the 7 GRN roles above), then each module's own roles/users/domain
        // data. Order matters — BOM's seeder assumes Master Data's RBAC tables
        // and roles already exist.
        $this->call(Tan90HouseRolesSeeder::class);
        $this->call(Tan90MasterDataSeeder::class);
        $this->call(Tan90MasterDataGapRolesSeeder::class);
        $this->call(Tan90BomRecipeCostingSeeder::class);
        $this->call(AccessControlSeeder::class);
        $this->call(ForgeAccessSeeder::class);
    }

    private function seedUsers(): void
    {
        $users = [
            ['name' => 'Ramesh Yadav', 'email' => 'guard@tan90.test', 'phone' => '+91 98200 11223', 'role' => Role::Guard, 'description' => 'Gate 1, day shift'],
            ['name' => 'Thermocore Materials Pvt Ltd', 'email' => 'vendor@tan90.test', 'phone' => '+91 22 6712 4400', 'role' => Role::Vendor, 'description' => 'Primary PCM compound vendor'],
            ['name' => 'Vikram Rao', 'email' => 'storeexec@tan90.test', 'phone' => '+91 98200 33445', 'role' => Role::StoreExec, 'description' => 'Unloading bay 3-4'],
            ['name' => 'Arjun Mehta', 'email' => 'qc@tan90.test', 'phone' => '+91 98200 55667', 'role' => Role::Qc, 'description' => 'QC — PCM compound line'],
            ['name' => 'Priya Deshmukh', 'email' => 'storemanager@tan90.test', 'phone' => '+91 98200 77889', 'role' => Role::StoreManager, 'description' => 'Bhiwandi warehouse'],
            ['name' => 'Farhan Ali', 'email' => 'finance@tan90.test', 'phone' => '+91 98200 99001', 'role' => Role::Finance, 'description' => 'Vendor payments'],
            ['name' => 'Priya Admin', 'email' => 'admin@tan90.test', 'phone' => '+91 98200 11000', 'role' => Role::Admin, 'description' => 'Founder — full access', 'super_admin' => true],
        ];

        foreach ($users as $user) {
            $email = $user['email'];
            unset($user['email']);
            User::firstOrCreate(['email' => $email], [...$user, 'password' => 'password']);
        }
    }

    private function seedSkuMaster(): void
    {
        SkuMaster::updateOrCreate(['sku' => 'PCM Raw Compound (TN-1 Grade)'], [
            'category' => 'PCM — Raw Material', 'unit' => 'KG',
            'default_bin' => 'BHW-PCM-A1', 'mapped' => true,
            'product_owner' => 'Priya Admin', 'product_code' => 'TN-1-PCM', 'active' => true,
            'vendor_name' => 'Thermocore Materials Pvt Ltd', 'manufacturer' => 'Thermocore Materials Pvt Ltd',
            'sales_start_date' => '2026-01-01', 'unit_price' => 42, 'tax' => 18, 'taxable' => true,
            'quantity_in_stock' => 690, 'handler' => 'Priya Deshmukh', 'qty_ordered' => 700, 'reorder_level' => 100,
            'quantity_in_demand' => 0,
            'description' => 'Primary phase-change compound, -1°C transition grade, used across the Tan90 TN-1 PCM product line.',
        ]);

        SkuMaster::updateOrCreate(['sku' => 'Wire Mesh — Structural Packaging'], [
            'category' => 'Packaging & Structural', 'unit' => 'NOS',
            'default_bin' => 'BHW-PCM-A2', 'mapped' => true,
            'product_owner' => 'Priya Admin', 'product_code' => 'WM-STRUCT-01', 'active' => true,
            'vendor_name' => 'Konkan Insulation Systems', 'manufacturer' => 'Konkan Insulation Systems',
            'unit_price' => 18, 'tax' => 18, 'taxable' => true,
            'quantity_in_stock' => 0, 'qty_ordered' => 450, 'reorder_level' => 50,
            'description' => 'Structural wire mesh used in PCM panel and blast-freezer wall assembly.',
        ]);

        SkuMaster::updateOrCreate(['sku' => 'Hand Gloves (PPE) — Bulk Pack'], [
            'category' => 'Safety & PPE', 'unit' => 'BOX',
            'default_bin' => 'BHW-PCM-A3', 'mapped' => true,
            'product_owner' => 'Priya Admin', 'product_code' => 'PPE-GLV-01', 'active' => true,
            'vendor_name' => 'Sagar Safety & Industrial Supplies',
            'unit_price' => 26, 'tax' => 5, 'taxable' => true,
            'quantity_in_stock' => 0, 'qty_ordered' => 300, 'reorder_level' => 25,
            'description' => 'Bulk-pack safety gloves for warehouse and installation crews.',
        ]);

        SkuMaster::updateOrCreate(['sku' => 'PCM Raw Compound (TN+29 Grade)'], [
            'category' => 'PCM — Trial', 'unit' => 'KG',
            'default_bin' => null, 'mapped' => false,
            'product_owner' => 'Priya Admin', 'product_code' => 'TN+29-PCM', 'active' => true,
            'vendor_name' => 'Trial Run Components', 'sales_start_date' => '2026-06-20',
            'unit_price' => 30, 'taxable' => true, 'quantity_in_stock' => 0, 'reorder_level' => 50,
            'description' => 'Trial +29°C transition grade compound — not yet mapped for the gate SKU check.',
        ]);
    }

    private function seedVendorMaster(): void
    {
        VendorMaster::updateOrCreate(['vendor_name' => 'Thermocore Materials Pvt Ltd'], [
            'gst_number' => '27AACCH1234K1Z5',
            'contact_phone' => '+91 22 6712 4400', 'contact_email' => 'accounts@thermocorematerials.example',
            'category' => 'Raw Material — PCM Compound', 'active' => true,
            'vendor_owner' => 'Priya Admin', 'website' => 'www.thermocorematerials.example',
            'gl_account' => 'Raw Materials — COGS', 'email_opt_out' => false,
            'address_country' => 'India', 'address_building' => 'Plot 14, MIDC Industrial Estate',
            'address_street' => 'Thane-Belapur Road', 'address_city' => 'Thane', 'address_state' => 'Maharashtra', 'address_zip' => '400604',
            'description' => 'Primary raw-material supplier — PCM Raw Compound (TN-1 Grade), on a standing PO since 2023.',
        ]);

        VendorMaster::updateOrCreate(['vendor_name' => 'Konkan Insulation Systems'], [
            'gst_number' => '27AAFCK5566L1Z2',
            'contact_phone' => '+91 22 6600 1122', 'contact_email' => 'sales@konkaninsulation.example',
            'category' => 'Packaging & Structural', 'active' => true,
            'vendor_owner' => 'Priya Admin', 'website' => 'www.konkaninsulation.example', 'gl_account' => 'Packaging — COGS',
            'address_country' => 'India', 'address_city' => 'Ratnagiri', 'address_state' => 'Maharashtra', 'address_zip' => '415612',
        ]);

        VendorMaster::updateOrCreate(['vendor_name' => 'Sagar Safety & Industrial Supplies'], [
            'gst_number' => '27AADCS7788M1Z9',
            'contact_phone' => '+91 22 6600 3344', 'contact_email' => 'orders@sagarsafety.example',
            'category' => 'Safety & PPE', 'active' => true, 'gl_account' => 'Consumables — Expense',
            'address_country' => 'India', 'address_city' => 'Vapi', 'address_state' => 'Gujarat', 'address_zip' => '396195',
        ]);

        VendorMaster::updateOrCreate(['vendor_name' => 'Trial Run Components'], [
            'gst_number' => '27AAGCT9911N1Z4',
            'contact_phone' => '+91 22 6600 5566', 'category' => 'Trial vendor', 'active' => false,
            'gl_account' => 'Other Purchases',
            'description' => 'On trial for the PCM Raw Compound (TN+29 Grade) line — not yet a confirmed vendor.',
        ]);
    }

    private function seedPurchaseOrders(): void
    {
        $po1 = PurchaseOrder::updateOrCreate(['po_number' => 'PO RM 2627 0020'], [
            'po_owner' => 'Priya Admin',
            'subject' => 'Raw material — PCM Raw Compound (TN-1 Grade) restock',
            'vendor_name' => 'Thermocore Materials Pvt Ltd',
            'po_date' => '2026-06-20', 'due_date' => '2026-06-26', 'status' => 'Delivered',
        ]);
        $po1->lines()->updateOrCreate(['product' => 'PCM Raw Compound (TN-1 Grade)'], ['quantity' => 700, 'list_price' => 42]);

        $po2 = PurchaseOrder::updateOrCreate(['po_number' => 'PO RM 2627 0031'], [
            'po_owner' => 'Priya Admin',
            'subject' => 'Raw material — Wire Mesh — Structural Packaging',
            'vendor_name' => 'Konkan Insulation Systems',
            'po_date' => '2026-06-24', 'due_date' => '2026-06-30', 'status' => 'Approved',
        ]);
        $po2->lines()->updateOrCreate(['product' => 'Wire Mesh — Structural Packaging'], ['quantity' => 450, 'list_price' => 18]);

        $po3 = PurchaseOrder::updateOrCreate(['po_number' => 'PO RM 2627 0018'], [
            'po_owner' => 'Priya Admin',
            'subject' => 'Raw material — Hand Gloves (PPE) — Bulk Pack',
            'vendor_name' => 'Sagar Safety & Industrial Supplies',
            'po_date' => '2026-06-22', 'due_date' => '2026-06-28', 'status' => 'Approved',
        ]);
        $po3->lines()->updateOrCreate(['product' => 'Hand Gloves (PPE) — Bulk Pack'], ['quantity' => 300, 'list_price' => 26]);

        $po4 = PurchaseOrder::updateOrCreate(['po_number' => 'PO RM 2627 0045'], [
            'po_owner' => 'Priya Admin',
            'subject' => 'Trial — PCM Raw Compound (TN+29 Grade), new SKU',
            'vendor_name' => 'Trial Run Components',
            'po_date' => '2026-06-20', 'due_date' => '2026-06-27', 'status' => 'Created',
        ]);
        $po4->lines()->updateOrCreate(['product' => 'PCM Raw Compound (TN+29 Grade)'], ['quantity' => 200, 'list_price' => 30]);

        // The client's own real test record from their Zoho CRM, kept as a
        // reference — not a fictional demo entry. May already exist from a
        // manual Zoho-fetch test; updateOrCreate just fills in the rest.
        $po5 = PurchaseOrder::updateOrCreate(['po_number' => '898897889'], [
            'subject' => 'INQUIRY', 'requisition_number' => '86868689',
            'vendor_name' => 'Tasmiya', 'contact_name' => 'Ravinder B',
            'po_date' => '2026-07-07', 'due_date' => '2026-07-15', 'status' => 'Created', 'carrier' => 'FedEx',
        ]);
        $po5->lines()->updateOrCreate(['product' => 'Freight/Shipping Charges'], ['quantity' => 1, 'list_price' => 1]);
    }

    private function seedGateEntriesAndDownstream(): void
    {
        // Closed-loop demo: Thermocore Materials Pvt Ltd, PO RM 2627 0020.
        // Walked fully through Guard -> Unloading -> GRN -> Ledger -> Finance
        // so a fresh install already shows one complete, trustworthy story.
        $tcmGate = GateEntry::updateOrCreate(['gate_no' => 'GATE-1001'], [
            'entry_type' => 'inward',
            'po_number' => 'PO RM 2627 0020', 'vendor_name' => 'Thermocore Materials Pvt Ltd',
            'vendor_gst' => '27AACCH1234K1Z5', 'invoice_number' => 'TCM/INV/4471', 'invoice_qty' => 700,
            'rate' => 42, 'material' => 'PCM Raw Compound (TN-1 Grade)',
            'vehicle_number' => 'MH 04 GT 5521', 'driver_name' => 'Ramesh Yadav', 'transporter' => 'Shree Logistics',
            'location' => 'Bhiwandi', 'gps' => '19.2967 N, 73.0631 E', 'bill_scanned' => true,
            'status' => 'closed', 'sla_deadline' => '2026-06-25 21:12:04', 'created_at' => '2026-06-25 09:12:04',
        ]);

        $gate2 = GateEntry::updateOrCreate(['gate_no' => 'GATE-1014'], [
            'entry_type' => 'inward',
            'po_number' => 'PO RM 2627 0031', 'vendor_name' => 'Konkan Insulation Systems',
            'invoice_number' => 'KIS/INV/1187', 'invoice_qty' => 450, 'material' => 'Wire Mesh — Structural Packaging',
            'vehicle_number' => 'MH 12 KL 9902', 'driver_name' => 'Suresh Patil', 'transporter' => 'Konkan Freight',
            'location' => 'Bhiwandi', 'gps' => '19.2971 N, 73.0644 E', 'bill_scanned' => true,
            'status' => 'pending_validation', 'sla_deadline' => '2026-06-30 16:32:51', 'created_at' => '2026-06-30 04:32:51',
        ]);

        $gate3 = GateEntry::updateOrCreate(['gate_no' => 'GATE-1015'], [
            'entry_type' => 'inward',
            'po_number' => 'PO RM 2627 0031', 'vendor_name' => 'Konkan Insulation Systems',
            'invoice_number' => 'KIS/INV/1187', 'invoice_qty' => 450, 'material' => 'Wire Mesh — Structural Packaging',
            'vehicle_number' => 'MH 12 KL 4410', 'driver_name' => 'Anil More', 'transporter' => 'Konkan Freight',
            'location' => 'Bhiwandi', 'gps' => '19.2971 N, 73.0644 E', 'bill_scanned' => true,
            'status' => 'pending_validation', 'sla_deadline' => '2026-06-30 16:21:38', 'created_at' => '2026-06-30 04:21:38',
        ]);

        $gate4 = GateEntry::updateOrCreate(['gate_no' => 'GATE-1009'], [
            'entry_type' => 'inward',
            'po_number' => 'PO RM 2627 0018', 'vendor_name' => 'Sagar Safety & Industrial Supplies',
            'invoice_number' => 'SSIS/INV/8820', 'invoice_qty' => 300, 'material' => 'Hand Gloves (PPE) — Bulk Pack',
            'vehicle_number' => 'MH 04 BT 7712', 'driver_name' => 'Vijay Sharma', 'transporter' => 'Sagar Transport',
            'location' => 'Bhiwandi', 'gps' => '19.2968 N, 73.0629 E', 'bill_scanned' => true,
            'status' => 'pending_validation', 'sla_deadline' => '2026-06-28 11:10:08', 'created_at' => '2026-06-27 23:10:08',
        ]);

        ValidationIssue::updateOrCreate(
            ['gate_entry_id' => $gate2->id, 'code' => 'DUP_INVOICE'],
            ['title' => 'Duplicate Invoice', 'description' => 'Possible duplicate invoice detected', 'severity' => 'hardFail', 'status' => 'open', 'created_at' => '2026-06-30 04:32:51']
        );
        ValidationIssue::updateOrCreate(
            ['gate_entry_id' => $gate3->id, 'code' => 'DUP_INVOICE'],
            ['title' => 'Duplicate Invoice', 'description' => 'Possible duplicate invoice detected', 'severity' => 'hardFail', 'status' => 'open', 'created_at' => '2026-06-30 04:21:38']
        );
        ValidationIssue::updateOrCreate(
            ['gate_entry_id' => $gate4->id, 'code' => 'DUP_INVOICE'],
            ['title' => 'Duplicate Invoice', 'description' => 'Possible duplicate invoice detected', 'severity' => 'hardFail', 'status' => 'open', 'created_at' => '2026-06-27 23:10:08']
        );

        VendorSubmission::updateOrCreate(
            ['po_number' => 'PO RM 2627 0020', 'invoice_number' => 'TCM/INV/4471'],
            [
                'vendor_name' => 'Thermocore Materials Pvt Ltd',
                'invoice_qty' => 700, 'material' => 'PCM Raw Compound (TN-1 Grade)',
                'has_invoice' => true, 'has_eway_bill' => true, 'has_lr_pod' => true,
                'status' => 'acknowledged', 'created_at' => '2026-06-25 07:40:00',
            ]
        );

        UnloadingRecord::updateOrCreate(
            ['gate_entry_id' => $tcmGate->id],
            [
                'box_count' => 28, 'staging_area' => 'Staging Bay 3',
                'unloaded_by' => 'Prakash Store Team', 'pod_lr_ref' => 'LR-88213',
                'started_at' => '2026-06-25 09:40:00', 'completed_at' => '2026-06-25 10:55:00',
            ]
        );

        GrnRecord::updateOrCreate(
            ['gate_entry_id' => $tcmGate->id, 'sku' => 'PCM Raw Compound (TN-1 Grade)'],
            [
                'po_qty' => 700, 'invoice_qty' => 700, 'physical_received' => 695,
                'accepted_qty' => 690, 'qc_hold_qty' => 0, 'defective_qty' => 5, 'rejected_qty' => 0, 'missing_qty' => 5,
                'qc_reasons' => 'Minor bag damage on 1 pallet, 5 KG loss confirmed',
                'suggested_bin' => 'BHW-PCM-A1', 'posted' => true, 'created_at' => '2026-06-25 11:20:00',
            ]
        );

        LedgerEntry::updateOrCreate(
            ['gate_entry_id' => $tcmGate->id, 'sku' => 'PCM Raw Compound (TN-1 Grade)', 'bucket' => 'available'],
            ['bin' => 'BHW-PCM-A1', 'qty' => 690, 'created_at' => '2026-06-25 11:22:00']
        );
        LedgerEntry::updateOrCreate(
            ['gate_entry_id' => $tcmGate->id, 'sku' => 'PCM Raw Compound (TN-1 Grade)', 'bucket' => 'defective'],
            ['bin' => 'BHW-PCM-A1', 'qty' => 5, 'created_at' => '2026-06-25 11:22:00']
        );

        FinanceRecord::updateOrCreate(
            ['gate_entry_id' => $tcmGate->id, 'invoice_number' => 'TCM/INV/4471'],
            [
                'vendor_name' => 'Thermocore Materials Pvt Ltd',
                'rate_per_unit' => 42, 'invoice_value' => 700 * 42, 'accepted_value' => 690 * 42,
                'deduction_defective' => 5 * 42, 'deduction_rejected' => 0, 'deduction_missing' => 5 * 42,
                'final_payable' => 690 * 42, 'vendor_status' => 'cleared',
                'notes' => 'Defective + missing deducted, debit note DN-3312 issued', 'created_at' => '2026-06-25 12:00:00',
            ]
        );
    }

    private function seedVendorStockUpdates(): void
    {
        VendorStockUpdate::updateOrCreate(
            ['vendor_name' => 'Thermocore Materials Pvt Ltd', 'material' => 'PCM Raw Compound (TN-1 Grade)'],
            ['quantity' => 850, 'unit' => 'KG', 'note' => 'Ready to dispatch against next PO', 'created_at' => '2026-07-02 10:15:00']
        );
        VendorStockUpdate::updateOrCreate(
            ['vendor_name' => 'Thermocore Materials Pvt Ltd', 'material' => 'PCM Raw Compound (TN+29 Grade)'],
            ['quantity' => 200, 'unit' => 'KG', 'created_at' => '2026-06-28 16:40:00']
        );
    }
}
