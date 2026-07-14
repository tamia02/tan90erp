<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_data_quality_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('entity');
            $table->string('description');
            $table->enum('default_severity', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_data_quality_rules');
    }
};
