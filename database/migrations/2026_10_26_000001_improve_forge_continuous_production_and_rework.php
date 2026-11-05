<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_work_orders', function (Blueprint $table): void {
            $table->unsignedInteger('rework_generation')->default(0);
            $table->decimal('qc_accepted_carry_qty', 14, 3)->default(0);
            $table->decimal('qc_rejected_carry_qty', 14, 3)->default(0);
        });

        Schema::table('forge_mixing_records', function (Blueprint $table): void {
            $table->string('pcm_uom', 20)->nullable();
            $table->unsignedSmallInteger('time_to_machine_minutes')->nullable();
        });

        Schema::table('forge_process_records', function (Blueprint $table): void {
            $table->unsignedInteger('cycle_no')->default(1);
            $table->string('machine_type', 20)->default('manual');
            $table->decimal('continuous_feed_qty', 14, 3)->nullable();
            $table->decimal('recovered_pcm_used_qty', 14, 3)->default(0);
            $table->decimal('power_kwh', 14, 3)->nullable();
            $table->unsignedSmallInteger('idle_minutes')->default(0);
            $table->string('idle_reason', 255)->nullable();
            $table->unsignedSmallInteger('breakdown_minutes')->default(0);
            $table->string('breakdown_reason', 255)->nullable();
            $table->string('wastage_reason', 255)->nullable();
        });

        Schema::table('forge_production_entries', function (Blueprint $table): void {
            $table->unsignedInteger('cycle_no')->default(1);
        });

        Schema::table('forge_final_qc_results', function (Blueprint $table): void {
            $table->decimal('cycle_accepted_qty', 14, 3)->nullable();
            $table->decimal('cycle_rejected_qty', 14, 3)->nullable();
            $table->decimal('cycle_rework_qty', 14, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forge_final_qc_results', fn (Blueprint $table) => $table->dropColumn([
            'cycle_accepted_qty', 'cycle_rejected_qty', 'cycle_rework_qty',
        ]));
        Schema::table('forge_production_entries', fn (Blueprint $table) => $table->dropColumn('cycle_no'));
        Schema::table('forge_process_records', fn (Blueprint $table) => $table->dropColumn([
            'cycle_no', 'machine_type', 'continuous_feed_qty', 'recovered_pcm_used_qty', 'power_kwh', 'idle_minutes', 'idle_reason',
            'breakdown_minutes', 'breakdown_reason',
            'wastage_reason',
        ]));
        Schema::table('forge_mixing_records', fn (Blueprint $table) => $table->dropColumn(['pcm_uom', 'time_to_machine_minutes']));
        Schema::table('forge_work_orders', fn (Blueprint $table) => $table->dropColumn([
            'rework_generation', 'qc_accepted_carry_qty', 'qc_rejected_carry_qty',
        ]));
    }
};
