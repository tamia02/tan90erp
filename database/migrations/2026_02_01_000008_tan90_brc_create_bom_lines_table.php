<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_bom_version_id')->constrained('tan90_bom_versions')->cascadeOnDelete();
            $table->enum('line_type', ['component', 'sub_bom'])->default('component');
            $table->foreignId('tan90_component_id')->nullable()->constrained('tan90_components')->restrictOnDelete();
            // Self-reference for nested/packaging BOMs; CircularReferenceService walks this chain.
            $table->foreignId('tan90_sub_bom_id')->nullable()->constrained('tan90_boms')->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('quantity', 14, 4);
            $table->string('uom')->nullable();
            $table->decimal('wastage_percent', 5, 2)->default(0);
            $table->boolean('is_alternate')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_bom_lines');
    }
};
