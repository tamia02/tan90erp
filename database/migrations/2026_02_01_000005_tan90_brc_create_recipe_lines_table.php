<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_recipe_version_id')->constrained('tan90_recipe_versions')->cascadeOnDelete();
            $table->foreignId('tan90_component_id')->constrained('tan90_components')->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('percentage', 7, 4);
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('uom')->nullable();
            $table->decimal('wastage_percent', 5, 2)->default(0);
            $table->boolean('is_alternate')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_recipe_lines');
    }
};
