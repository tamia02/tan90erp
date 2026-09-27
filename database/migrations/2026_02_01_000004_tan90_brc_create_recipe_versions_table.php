<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_recipe_id')->constrained('tan90_recipes')->cascadeOnDelete();
            $table->string('revision_code');
            $table->unsignedInteger('revision_number')->default(1);
            // P0 workflow: Draft -> Technical Review -> QA Review -> Cost Review -> Plant Trial -> Release -> MRP Ready
            $table->enum('gate_status', ['draft', 'technical_review', 'qa_review', 'cost_review', 'plant_trial', 'released', 'mrp_ready', 'superseded'])->default('draft');
            $table->boolean('is_current')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            // FK added later (2026_02_01_000027) once tan90_engineering_change_orders exists.
            $table->unsignedBigInteger('tan90_engineering_change_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tan90_recipe_id', 'revision_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_recipe_versions');
    }
};
