<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_plants', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('tan90_business_unit_id')->constrained('tan90_business_units')->restrictOnDelete();
            $table->foreignId('tan90_location_id')->constrained('tan90_locations')->restrictOnDelete();
            $table->enum('plant_type', ['Manufacturing Unit', 'Warehouse', 'R&D Center', 'Office'])->default('Manufacturing Unit');
            $table->string('manager')->nullable();
            $table->string('phone')->nullable();
            $table->string('capacity')->nullable();
            $table->enum('shift_model', ['General', '2 Shift', '3 Shift', '24x7'])->default('General');
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
        Schema::dropIfExists('tan90_plants');
    }
};
