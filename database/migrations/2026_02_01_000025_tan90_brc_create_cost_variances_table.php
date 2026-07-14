<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_cost_variances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_cost_sheet_id')->constrained('tan90_cost_sheets')->cascadeOnDelete();
            $table->string('variance_type');
            $table->decimal('standard_cost', 14, 4);
            $table->decimal('actual_cost', 14, 4);
            $table->decimal('variance_amount', 14, 4);
            $table->decimal('variance_percent', 6, 2);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_cost_variances');
    }
};
