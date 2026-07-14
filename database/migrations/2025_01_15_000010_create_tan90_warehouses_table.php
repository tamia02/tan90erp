<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('tan90_plant_id')->constrained('tan90_plants')->restrictOnDelete();
            $table->foreignId('tan90_location_id')->constrained('tan90_locations')->restrictOnDelete();
            $table->enum('warehouse_type', ['Raw Material', 'Finished Goods', 'Central Distribution', 'QC Hold', 'Rejected', 'In Transit'])->default('Raw Material');
            $table->string('manager')->nullable();
            $table->string('capacity')->nullable();
            $table->string('temperature_zone')->nullable();
            $table->enum('bin_tracking', ['enabled', 'disabled'])->default('enabled');
            $table->string('status')->default('active');
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_warehouses');
    }
};
