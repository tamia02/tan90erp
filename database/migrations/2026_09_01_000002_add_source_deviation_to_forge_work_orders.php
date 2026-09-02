<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a rework work order back to the deviation that spawned it. Reworking
 * a deviated/scrapped quantity reuses the full existing WO lifecycle (release,
 * material issue, job cards, final QC) rather than a parallel "rework order"
 * entity — this column is the only new thing a rework WO needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_work_orders', function (Blueprint $table) {
            $table->foreignId('source_deviation_id')->nullable()->after('production_plan_id')
                ->constrained('forge_deviations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forge_work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_deviation_id');
        });
    }
};
