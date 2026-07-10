<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('validation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_entry_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->text('description');
            $table->string('severity'); // hardFail | redFlag | warning
            $table->string('status')->default('open'); // open | approved | resolved | escalated
            $table->string('owner')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_issues');
    }
};
