<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('tan90_warehouse_id')->constrained('tan90_warehouses')->cascadeOnDelete();
            $table->enum('zone_type', ['Raw Material Zone', 'Chemical Safe Zone', 'Packaging Zone', 'QC Hold Zone', 'Defective Zone', 'Rejected Zone', 'Finished Goods Zone'])->default('Raw Material Zone');
            $table->string('allowed_material')->nullable();
            $table->enum('hazard_class', ['None', 'Controlled', 'Segregated', 'Isolated'])->default('None');
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
        Schema::dropIfExists('tan90_warehouse_zones');
    }
};
