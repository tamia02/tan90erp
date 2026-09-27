<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_change_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_engineering_change_order_id')->constrained('tan90_engineering_change_orders')->cascadeOnDelete();
            $table->string('impacted_object_type');
            $table->unsignedBigInteger('impacted_object_id');
            $table->text('impact_description')->nullable();
            $table->timestamps();

            $table->index(['impacted_object_type', 'impacted_object_id'], 'tan90_change_impacts_object_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_change_impacts');
    }
};
