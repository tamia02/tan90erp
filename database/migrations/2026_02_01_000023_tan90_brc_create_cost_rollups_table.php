<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_cost_rollups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_finished_good_id')->constrained('tan90_finished_goods')->restrictOnDelete();
            $table->foreignId('tan90_bom_version_id')->constrained('tan90_bom_versions')->restrictOnDelete();
            $table->string('cost_period');
            // Hash of the exact inputs (rates + BOM revision) used, so a repeat
            // roll-up request with unchanged inputs is a no-op (idempotency key).
            $table->string('input_hash');
            $table->decimal('material_cost', 14, 4)->default(0);
            $table->decimal('labor_cost', 14, 4)->default(0);
            $table->decimal('machine_cost', 14, 4)->default(0);
            $table->decimal('utility_cost', 14, 4)->default(0);
            $table->decimal('overhead_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->string('status')->default('completed');
            $table->timestamp('rolled_up_at')->nullable();
            $table->foreignId('rolled_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tan90_finished_good_id', 'tan90_bom_version_id', 'cost_period', 'input_hash'], 'tan90_cost_rollups_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_cost_rollups');
    }
};
