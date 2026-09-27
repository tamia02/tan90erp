<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_quality_parameters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('tan90_item_category_id')->nullable()->constrained('tan90_item_categories')->nullOnDelete();
            $table->enum('data_type', ['Decimal', 'Integer', 'Pass/Fail', 'Text', 'Option List'])->default('Text');
            $table->string('unit')->nullable();
            $table->string('min_value')->nullable();
            $table->string('max_value')->nullable();
            $table->string('sampling')->nullable();
            $table->enum('criticality', ['Critical', 'Major', 'Minor'])->default('Minor');
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
        Schema::dropIfExists('tan90_quality_parameters');
    }
};
