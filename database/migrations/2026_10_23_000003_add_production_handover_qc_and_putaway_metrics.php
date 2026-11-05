<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_work_orders', function (Blueprint $table): void {
            $table->dateTime('material_handover_started_at')->nullable();
            $table->dateTime('material_handover_ended_at')->nullable();
            $table->unsignedInteger('material_handover_manpower')->nullable();
            $table->foreignId('material_handover_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('forge_final_qc_results', function (Blueprint $table): void {
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('manpower')->nullable();
        });

        Schema::table('flow_inventory_lots', function (Blueprint $table): void {
            $table->dateTime('putaway_started_at')->nullable();
            $table->dateTime('putaway_ended_at')->nullable();
            $table->unsignedInteger('putaway_manpower')->nullable();
            $table->foreignId('putaway_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_inventory_lots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('putaway_by');
            $table->dropColumn(['putaway_started_at', 'putaway_ended_at', 'putaway_manpower']);
        });

        Schema::table('forge_final_qc_results', fn (Blueprint $table) => $table->dropColumn(['started_at', 'ended_at', 'manpower']));

        Schema::table('forge_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('material_handover_by');
            $table->dropColumn(['material_handover_started_at', 'material_handover_ended_at', 'material_handover_manpower']);
        });
    }
};
