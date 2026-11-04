<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoReadClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One-off clean-up before go-live (user, 2026-10-10: "remove all test work,
// keep the real Zoho data"). Empties every transaction table filled while
// testing (gate entries -> unloading -> QC -> GRN -> stock, productions,
// outward shipments, finance, vendor bills, demo work queue) and deletes the
// Purchase Orders that are NOT in the client's real Zoho. Keeps items,
// recipes, vendors, customers, locations, users, roles and all settings.
// Always run --dry-run first; a database backup must exist.
class ClearTestWork extends Command
{
    protected $signature = 'data:clear-test-work {--dry-run : Only show what would be deleted} {--force : Required to actually delete}';

    protected $description = 'Delete all test transactions + POs not in the real Zoho; keep master data and Zoho records.';

    // Emptied completely. Children before parents.
    private const TABLES = [
        'qc_result_evidence_images', 'qc_result_lines', 'qc_results',
        'grn_record_lines', 'grn_records', 'ledger_entries', 'unloading_records', 'validation_issues',
        'debit_notes', 'finance_records', 'supplier_claims', 'vendor_stock_updates', 'vendor_submissions', 'rfqs',
        'outward_shipment_lines', 'outward_shipments', 'inventory_movements', 'inventory_dispatches', 'inventory_productions',
        'gate_entries',
        'workspace_task_events', 'workspace_tasks', 'workspace_approval_events', 'workspace_approvals', 'workspace_exception_events', 'workspace_exceptions',
    ];

    // Their Zoho sync links go with them.
    private const LINKED_MODELS = [
        'App\\Models\\GateEntry', 'App\\Models\\FinanceRecord', 'App\\Models\\GrnRecord', 'App\\Models\\VendorSubmission',
        'App\\Models\\Inventory\\InventoryProduction', 'App\\Models\\Inventory\\InventoryDispatch', 'App\\Models\\Outward\\OutwardShipment',
    ];

    public function handle(ZohoReadClient $zoho): int
    {
        if (! $zoho->configured()) {
            $this->error('Zoho is not configured - cannot tell real POs from test POs. Nothing deleted.');

            return self::FAILURE;
        }
        $this->info('Reading the PO list from the real Zoho (read-only)...');
        $zohoPos = collect($zoho->all('/purchaseorders', 'purchaseorders'))->pluck('purchaseorder_number')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter()->flip();
        $this->line("Zoho has {$zohoPos->count()} POs.");

        $pos = DB::table('purchase_orders')->get(['id', 'po_number']);
        $keepPos = $pos->filter(fn ($p) => $zohoPos->has(mb_strtolower(trim($p->po_number))));
        $dropPos = $pos->reject(fn ($p) => $zohoPos->has(mb_strtolower(trim($p->po_number))));

        $rows = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $rows[] = [$table, DB::table($table)->count()];
            }
        }
        $rows[] = ['purchase_orders (not in Zoho)', $dropPos->count()];
        $rows[] = ['purchase_order_lines (of those)', DB::table('purchase_order_lines')->whereIn('purchase_order_id', $dropPos->pluck('id'))->count()];
        $rows[] = ['purchase_order_acknowledgements (of those)', DB::table('purchase_order_acknowledgements')->whereIn('po_number', $dropPos->pluck('po_number'))->count()];
        $rows[] = ['inventory_items with opening stock (set to 0)', DB::table('inventory_items')->where('opening_qty', '!=', 0)->count()];
        $this->table(['Will delete', 'Rows'], $rows);
        $this->line('Test POs: '.$dropPos->pluck('po_number')->implode(', '));
        $this->line("Keeping {$keepPos->count()} real Zoho POs, all items, recipes, vendors, customers, locations, users, roles and settings.");

        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing deleted.');

            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->error('Add --force to delete (after taking a database backup).');

            return self::FAILURE;
        }

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($dropPos) {
                foreach (self::TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
                DB::table('purchase_order_acknowledgements')->whereIn('po_number', $dropPos->pluck('po_number'))->delete();
                DB::table('purchase_order_lines')->whereIn('purchase_order_id', $dropPos->pluck('id'))->delete();
                DB::table('purchase_orders')->whereIn('id', $dropPos->pluck('id'))->delete();
                DB::table('zoho_entity_links')->whereIn('syncable_type', self::LINKED_MODELS)->delete();
                DB::table('zoho_entity_links')->where('syncable_type', 'App\\Models\\PurchaseOrder')->whereIn('syncable_id', $dropPos->pluck('id'))->delete();
                DB::table('inventory_items')->where('opening_qty', '!=', 0)->update(['opening_qty' => 0]);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Test work deleted.');

        return self::SUCCESS;
    }
}
