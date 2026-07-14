<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_approval_step_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tan90_approval_progress_id')->constrained('tan90_approval_progress')->cascadeOnDelete();
            // Named explicitly - the auto-generated FK name
            // ("tan90_approval_step_decisions_tan90_approval_workflow_step_id_foreign")
            // exceeds MySQL's 64-character identifier limit.
            $table->foreignId('tan90_approval_workflow_step_id')->nullable();
            $table->foreign('tan90_approval_workflow_step_id', 'tan90_asd_step_fk')
                ->references('id')->on('tan90_approval_workflow_steps')->nullOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->enum('decision', ['approved', 'rejected'])->default('approved');
            $table->text('notes')->nullable();
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_approval_step_decisions');
    }
};
