<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_item_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_item_id')->constrained('tan90_items')->cascadeOnDelete();
            $table->foreignId('tan90_uom_id')->constrained('tan90_uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 18, 6)->default(1);
            $table->string('status')->default('active');
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tan90_item_id', 'tan90_uom_id'], 'tan90_item_uom_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_item_uom_conversions');
    }
};
