<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_process_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_routing_operation_id')->constrained('tan90_routing_operations')->cascadeOnDelete();
            $table->string('parameter_name');
            $table->string('target_value')->nullable();
            $table->string('min_value')->nullable();
            $table->string('max_value')->nullable();
            $table->string('uom')->nullable();
            $table->enum('criticality', ['Critical', 'Major', 'Minor'])->default('Major');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_process_parameters');
    }
};
