<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('tan90_approval_workflow_id')->constrained('tan90_approval_workflows')->cascadeOnDelete();
            $table->unsignedInteger('step_order')->default(1);
            $table->string('step_role');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_approval_workflow_steps');
    }
};
