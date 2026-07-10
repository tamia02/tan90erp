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
        Schema::create('unloading_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('box_count');
            $table->string('staging_area');
            $table->string('unloaded_by');
            $table->string('pod_lr_ref')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unloading_records');
    }
};
