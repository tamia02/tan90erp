<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('parent')->nullable();
            $table->enum('valuation_method', ['FIFO', 'Weighted Average', 'Standard Cost'])->default('FIFO');
            $table->enum('qc_required', ['Yes', 'No'])->default('Yes');
            $table->enum('batch_tracking', ['Yes', 'No', 'Optional'])->default('Optional');
            $table->string('shelf_life')->nullable();
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
        Schema::dropIfExists('tan90_item_categories');
    }
};
