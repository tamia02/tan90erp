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
        Schema::create('qc_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->integer('po_qty');
            $table->integer('invoice_qty');
            $table->integer('physical_received');
            // Flattened QcSplit — accepted/hold/defective/rejected, so
            // reports can sum these directly instead of unpacking JSON.
            $table->integer('accepted_qty')->default(0);
            $table->integer('qc_hold_qty')->default(0);
            $table->integer('defective_qty')->default(0);
            $table->integer('rejected_qty')->default(0);
            $table->integer('missing_qty')->default(0);
            $table->text('qc_reasons')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qc_results');
    }
};
