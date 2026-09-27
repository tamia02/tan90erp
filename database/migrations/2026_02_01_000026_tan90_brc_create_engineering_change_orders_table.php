<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_engineering_change_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('object_type', ['recipe', 'bom', 'routing', 'cost_standard'])->default('recipe');
            $table->unsignedBigInteger('object_id');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'approved', 'implemented'])->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_engineering_change_orders');
    }
};
