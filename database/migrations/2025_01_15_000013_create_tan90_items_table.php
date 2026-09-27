<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('tan90_item_category_id')->constrained('tan90_item_categories')->restrictOnDelete();
            $table->foreignId('tan90_uom_id')->constrained('tan90_uoms')->restrictOnDelete();
            // HSN and temperature class are stored as plain text in Phase 1; hsn_tax_rules
            // and temperature_classes become FK lookups once those Phase 2 domains land.
            $table->string('hsn')->nullable();
            $table->string('masking_code')->nullable();
            $table->enum('qc_required', ['Yes', 'No'])->default('Yes');
            $table->enum('batch_tracking', ['Yes', 'No', 'Optional'])->default('Optional');
            $table->string('temperature')->nullable();
            $table->string('storage')->nullable();
            $table->string('reorder_level')->nullable();
            $table->decimal('standard_cost', 14, 2)->nullable();
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
        Schema::dropIfExists('tan90_items');
    }
};
