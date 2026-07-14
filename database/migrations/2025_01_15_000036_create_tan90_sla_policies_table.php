<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('applies_to');
            $table->string('target');
            $table->string('warning_at')->nullable();
            $table->string('escalate_at')->nullable();
            $table->string('escalation_role')->nullable();
            $table->enum('calendar', ['24x7', 'Business Hours'])->default('Business Hours');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_sla_policies');
    }
};
