<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_number_series', function (Blueprint $table) {
            $table->id();
            $table->string('module')->unique();
            $table->string('prefix')->nullable();
            $table->string('pattern');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->enum('reset_policy', ['Never', 'Yearly', 'Monthly', 'Daily'])->default('Never');
            $table->string('preview')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_number_series');
    }
};
