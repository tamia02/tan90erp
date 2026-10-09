<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Wipes only the Inventory module's own tables so a demo / video can start
// clean. GRN, ledger, POs and everything else are untouched. Never runs in
// production.
class InventoryResetDemoCommand extends Command
{
    protected $signature = 'inventory:reset-demo {--force : Skip the confirmation question}';

    protected $description = 'Delete all Inventory module items, recipes, productions and dispatches (local/demo only).';

    private const TABLES = ['outward_shipment_lines', 'outward_shipments', 'inventory_movements', 'inventory_dispatches', 'inventory_productions', 'inventory_bom_lines', 'inventory_items'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        if (! $this->option('force') && ! $this->confirm("Delete ALL Inventory items, recipes, productions and dispatches in database [{$database}]? GRN stock is not touched.")) {
            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            DB::table($table)->truncate();
        }
        Schema::enableForeignKeyConstraints();

        $this->info("Inventory data cleared in [{$database}]. Start again at Inventory > 1. Items.");

        return self::SUCCESS;
    }
}
