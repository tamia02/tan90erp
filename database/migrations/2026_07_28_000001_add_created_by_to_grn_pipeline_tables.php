<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Needed so a Head/Manager's combined team dashboard can count only the
// work their own direct+indirect reports actually did, instead of a
// company-wide total - these four tables previously had zero user
// attribution (only a gate_entry_id chain), so there was no column to
// scope by.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['gate_entries', 'qc_results', 'grn_records', 'finance_records'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'created_by')) {
                    $blueprint->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['gate_entries', 'qc_results', 'grn_records', 'finance_records'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'created_by')) {
                    $blueprint->dropConstrainedForeignId('created_by');
                }
            });
        }
    }
};
