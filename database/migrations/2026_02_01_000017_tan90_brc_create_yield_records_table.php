<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_yield_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_bom_version_id')->constrained('tan90_bom_versions')->cascadeOnDelete();
            $table->decimal('batch_size', 14, 4);
            $table->decimal('expected_yield', 14, 4)->nullable();
            $table->decimal('actual_yield', 14, 4)->nullable();
            $table->decimal('yield_percent', 5, 2)->nullable();
            $table->decimal('loss_percent', 5, 2)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_yield_records');
    }
};
