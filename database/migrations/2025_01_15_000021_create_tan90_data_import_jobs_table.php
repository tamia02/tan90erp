<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_data_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('file_hash', 64);
            $table->json('column_map')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->enum('result', ['pending', 'previewed', 'queued', 'processing', 'completed', 'completed_with_warnings', 'failed'])->default('pending');
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_data_import_jobs');
    }
};
