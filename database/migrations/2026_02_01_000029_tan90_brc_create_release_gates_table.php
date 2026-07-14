<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_release_gates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('object_type', ['recipe', 'bom', 'routing', 'cost_standard'])->default('recipe');
            $table->unsignedBigInteger('object_id');
            // P0 gate order: Draft -> Technical Review -> QA Review -> Cost Review -> Plant Trial -> Release -> MRP Ready.
            $table->enum('gate', ['Draft', 'Technical Review', 'QA Review', 'Cost Review', 'Plant Trial', 'Release', 'MRP Ready']);
            $table->enum('status', ['pending', 'passed', 'failed'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index(['object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_release_gates');
    }
};
