<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_approval_progress', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            // Nullable: only set when the entity's module matches an active
            // tan90_approval_workflow with steps. Every submitted record gets a
            // progress row (for SLA tracking) even when no workflow applies.
            $table->foreignId('tan90_approval_workflow_id')->nullable()->constrained('tan90_approval_workflows')->nullOnDelete();
            $table->unsignedInteger('current_step_order')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('sla_warned_at')->nullable();
            $table->timestamp('sla_escalated_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_approval_progress');
    }
};
