<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('module');
            $table->string('trigger');
            $table->text('steps')->nullable();
            $table->string('sla')->nullable();
            $table->string('escalation')->nullable();
            $table->string('version_label')->nullable();
            $table->enum('approval_status', ['draft', 'active', 'inactive'])->default('draft');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_approval_workflows');
    }
};
