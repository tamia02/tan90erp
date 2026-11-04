<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_result_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_result_id')->constrained('qc_results')->cascadeOnDelete();
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
            $table->decimal('invoice_amount', 14, 2)->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->text('qc_reasons')->nullable();
            $table->text('hold_reason')->nullable();
            $table->string('suggested_bin')->nullable();
            $table->json('parameters')->nullable();
            $table->json('documents_checked')->nullable();
            $table->json('evidence_images')->nullable();
            $table->timestamps();

            $table->index(['gate_entry_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_result_lines');
    }
};
