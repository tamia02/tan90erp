<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the composite indexes used by the role dashboards and ledger views.
     * The information_schema guard keeps this safe for databases that already
     * received an equivalent index in an earlier rollout.
     */
    public function up(): void
    {
        $indexes = [
            'purchase_orders' => [
                'tan90_po_number_idx' => ['po_number'],
                'tan90_po_vendor_release_idx' => ['vendor_name', 'released_at'],
                'tan90_po_status_updated_idx' => ['status', 'updated_at'],
            ],
            'gate_entries' => [
                'tan90_gate_po_status_idx' => ['po_number', 'status'],
                'tan90_gate_type_status_idx' => ['entry_type', 'status'],
                'tan90_gate_updated_idx' => ['updated_at'],
            ],
            'vendor_submissions' => [
                'tan90_submission_vendor_po_idx' => ['vendor_name', 'po_number'],
                'tan90_submission_created_idx' => ['created_at'],
            ],
            'qc_results' => [
                'tan90_qc_return_created_idx' => ['return_status', 'created_at'],
            ],
            'ledger_entries' => [
                'tan90_ledger_sku_bucket_bin_idx' => ['sku', 'bucket', 'bin'],
                'tan90_ledger_bin_bucket_sku_idx' => ['bin', 'bucket', 'sku'],
                'tan90_ledger_created_idx' => ['created_at'],
            ],
        ];

        foreach ($indexes as $table => $tableIndexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($tableIndexes as $name => $columns) {
                $exists = DB::table('information_schema.statistics')
                    ->where('table_schema', DB::raw('DATABASE()'))
                    ->where('table_name', $table)
                    ->where('index_name', $name)
                    ->exists();

                if (! $exists) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'purchase_orders' => ['tan90_po_number_idx', 'tan90_po_vendor_release_idx', 'tan90_po_status_updated_idx'],
            'gate_entries' => ['tan90_gate_po_status_idx', 'tan90_gate_type_status_idx', 'tan90_gate_updated_idx'],
            'vendor_submissions' => ['tan90_submission_vendor_po_idx', 'tan90_submission_created_idx'],
            'qc_results' => ['tan90_qc_return_created_idx'],
            'ledger_entries' => ['tan90_ledger_sku_bucket_bin_idx', 'tan90_ledger_bin_bucket_sku_idx', 'tan90_ledger_created_idx'],
        ] as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($names as $name) {
                try {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                } catch (Throwable) {
                    // A rollback should remain safe if a DBA removed one of
                    // these optional performance indexes manually.
                }
            }
        }
    }
};
