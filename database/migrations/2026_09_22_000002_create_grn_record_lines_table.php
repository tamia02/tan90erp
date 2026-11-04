<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_record_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_record_id')->constrained('grn_records')->cascadeOnDelete();
            $table->foreignId('gate_entry_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->integer('po_qty')->default(0);
            $table->integer('invoice_qty')->default(0);
            $table->integer('physical_received')->default(0);
            $table->integer('accepted_qty')->default(0);
            $table->integer('qc_hold_qty')->default(0);
            $table->integer('defective_qty')->default(0);
            $table->integer('rejected_qty')->default(0);
            $table->integer('missing_qty')->default(0);
            $table->text('qc_reasons')->nullable();
            $table->string('suggested_bin')->nullable();
            $table->timestamps();

            $table->index(['gate_entry_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_record_lines');
    }
};
