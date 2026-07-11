<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('validation_issues', 'type')) {
            Schema::table('validation_issues', function (Blueprint $table) {
                // Store Manager-raised issues (Not Mapped / Not Found Across
                // All Vendors) are SKU-level, not tied to one gate entry.
                $table->string('type')->nullable()->after('code');
                $table->string('sku')->nullable()->after('type');
            });
        }

        // The FK constraint's actual name doesn't always match Laravel's
        // naming convention (depends on how the table was created), so look
        // it up instead of assuming it. No doctrine/dbal installed, so
        // recreate the column to make it nullable instead of ->change()'ing
        // it (same pattern used for unloading_records.started_at).
        $constraints = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'validation_issues'
             AND COLUMN_NAME = 'gate_entry_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE validation_issues DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
        }

        Schema::table('validation_issues', function (Blueprint $table) {
            $table->dropColumn('gate_entry_id');
        });

        // gate_entries.id is a plain signed `int` (not bigint/unsigned) in
        // this schema, so the FK column has to match that exactly or MySQL
        // rejects it (errno 150) — can't use foreignId()'s
        // unsignedBigInteger here.
        Schema::table('validation_issues', function (Blueprint $table) {
            $table->integer('gate_entry_id')->nullable()->after('id');
            $table->foreign('gate_entry_id')->references('id')->on('gate_entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('validation_issues', function (Blueprint $table) {
            $table->dropColumn(['type', 'sku']);
        });
    }
};
