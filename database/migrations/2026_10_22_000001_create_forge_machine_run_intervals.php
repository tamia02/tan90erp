<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_machine_run_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('forge_machines')->restrictOnDelete();
            $table->foreignId('job_card_id')->constrained('forge_job_cards')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['machine_id', 'started_at', 'ended_at'], 'forge_machine_run_window_idx');
            $table->index(['job_card_id', 'ended_at'], 'forge_machine_run_open_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_machine_run_intervals');
    }
};
