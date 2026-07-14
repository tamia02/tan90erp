<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_work_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('plant')->nullable();
            $table->decimal('capacity_per_hour', 14, 4)->nullable();
            $table->decimal('labor_rate', 14, 4)->default(0);
            $table->decimal('machine_rate', 14, 4)->default(0);
            $table->decimal('overhead_rate', 14, 4)->default(0);
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
        Schema::dropIfExists('tan90_work_centers');
    }
};
