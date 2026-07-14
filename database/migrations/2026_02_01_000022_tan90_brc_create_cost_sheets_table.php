<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_cost_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_finished_good_id')->constrained('tan90_finished_goods')->restrictOnDelete();
            $table->string('cost_period');
            $table->decimal('material_cost', 14, 4)->default(0);
            $table->decimal('labor_cost', 14, 4)->default(0);
            $table->decimal('machine_cost', 14, 4)->default(0);
            $table->decimal('utility_cost', 14, 4)->default(0);
            $table->decimal('overhead_cost', 14, 4)->default(0);
            $table->decimal('landed_cost', 14, 4)->default(0);
            $table->decimal('total_standard_cost', 14, 4)->default(0);
            $table->decimal('total_actual_cost', 14, 4)->nullable();
            $table->string('status')->default('active');
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tan90_finished_good_id', 'cost_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_cost_sheets');
    }
};
