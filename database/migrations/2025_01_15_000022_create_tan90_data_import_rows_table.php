<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_data_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_data_import_job_id')->constrained('tan90_data_import_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            // source_row_key is the natural key value (e.g. sku/code) used together with the
            // job's file_hash + entity_type to make re-imports of the same file idempotent.
            $table->string('source_row_key')->nullable();
            $table->json('raw_data');
            $table->json('mapped_data')->nullable();
            $table->json('errors')->nullable();
            $table->enum('status', ['valid', 'invalid', 'duplicate', 'imported'])->default('valid');
            $table->unsignedBigInteger('matched_entity_id')->nullable();
            $table->timestamps();

            $table->index(['tan90_data_import_job_id', 'source_row_key'], 'tan90_dir_job_row_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_data_import_rows');
    }
};
