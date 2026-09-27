<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_quality_specs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_finished_good_id')->nullable()->constrained('tan90_finished_goods')->nullOnDelete();
            $table->foreignId('tan90_component_id')->nullable()->constrained('tan90_components')->nullOnDelete();
            $table->string('parameter_name');
            $table->string('min_value')->nullable();
            $table->string('max_value')->nullable();
            $table->string('uom')->nullable();
            $table->enum('criticality', ['Critical', 'Major', 'Minor'])->default('Major');
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
        Schema::dropIfExists('tan90_quality_specs');
    }
};
