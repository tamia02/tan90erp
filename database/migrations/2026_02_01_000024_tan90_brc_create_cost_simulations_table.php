<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_cost_simulations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_finished_good_id')->constrained('tan90_finished_goods')->restrictOnDelete();
            $table->foreignId('tan90_cost_sheet_id')->nullable()->constrained('tan90_cost_sheets')->nullOnDelete();
            $table->string('scenario_name');
            $table->json('adjustments')->nullable();
            $table->decimal('simulated_total_cost', 14, 4)->nullable();
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_cost_simulations');
    }
};
