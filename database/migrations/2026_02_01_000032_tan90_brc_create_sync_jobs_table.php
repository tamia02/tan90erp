<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type');
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_sync_jobs');
    }
};
