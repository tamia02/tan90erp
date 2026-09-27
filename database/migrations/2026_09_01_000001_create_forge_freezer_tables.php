<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_freezers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('plant')->nullable();
            $table->decimal('capacity', 14, 3)->nullable();
            $table->decimal('threshold_temp_min', 6, 2);
            $table->decimal('threshold_temp_max', 6, 2);
            $table->string('state', 20)->default('idle'); // idle, running, maintenance
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('forge_freezer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freezer_id')->constrained('forge_freezers')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('forge_batches')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // A batch may be conditioned in more than one freezer over its life
            // (moved between units), but never in two at once.
            $table->unique(['batch_id', 'started_at']);
        });

        Schema::create('forge_freezer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freezer_id')->constrained('forge_freezers')->cascadeOnDelete();
            $table->decimal('temperature', 6, 2);
            $table->decimal('humidity', 5, 2)->nullable();
            $table->boolean('is_alert')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['freezer_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_freezer_readings');
        Schema::dropIfExists('forge_freezer_logs');
        Schema::dropIfExists('forge_freezers');
    }
};
