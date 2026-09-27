<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_result_evidence_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_result_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->enum('image_type', ['Defective Image', 'Rejected Image']);
            $table->timestamps();
            $table->index(['qc_result_id', 'image_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_result_evidence_images');
    }
};
