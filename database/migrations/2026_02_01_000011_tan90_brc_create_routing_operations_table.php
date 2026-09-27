<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_routing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_routing_id')->constrained('tan90_routings')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('operation_name');
            $table->foreignId('tan90_work_center_id')->constrained('tan90_work_centers')->restrictOnDelete();
            $table->decimal('setup_time_minutes', 10, 2)->default(0);
            $table->decimal('run_time_minutes', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_routing_operations');
    }
};
