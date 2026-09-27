<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_substitution_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_recipe_line_id')->nullable()->constrained('tan90_recipe_lines')->nullOnDelete();
            $table->foreignId('tan90_component_id')->constrained('tan90_components')->restrictOnDelete();
            $table->foreignId('tan90_substitute_component_id')->constrained('tan90_components')->restrictOnDelete();
            $table->decimal('max_percentage', 5, 2)->nullable();
            $table->boolean('requires_approval')->default(true);
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
        Schema::dropIfExists('tan90_substitution_rules');
    }
};
