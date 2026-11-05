<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grn_record_lines')) {
            Schema::table('grn_record_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('grn_record_lines', 'unit_price')) {
                    $table->decimal('unit_price', 14, 4)->nullable();
                }
                if (! Schema::hasColumn('grn_record_lines', 'invoice_amount')) {
                    $table->decimal('invoice_amount', 16, 2)->nullable();
                }
                if (! Schema::hasColumn('grn_record_lines', 'accepted_value')) {
                    $table->decimal('accepted_value', 16, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('ledger_entries')) {
            Schema::table('ledger_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('ledger_entries', 'unit_price')) {
                    $table->decimal('unit_price', 14, 4)->nullable();
                }
                if (! Schema::hasColumn('ledger_entries', 'extended_value')) {
                    $table->decimal('extended_value', 16, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('grn_record_lines')) {
            Schema::table('grn_record_lines', function (Blueprint $table): void {
                $columns = array_values(array_filter(['unit_price', 'invoice_amount', 'accepted_value'], fn (string $column): bool => Schema::hasColumn('grn_record_lines', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('ledger_entries')) {
            Schema::table('ledger_entries', function (Blueprint $table): void {
                $columns = array_values(array_filter(['unit_price', 'extended_value'], fn (string $column): bool => Schema::hasColumn('ledger_entries', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
