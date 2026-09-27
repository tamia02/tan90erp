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
        Schema::create('finance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vendor_name');
            $table->string('invoice_number')->nullable();
            $table->decimal('rate_per_unit', 12, 2);
            $table->decimal('invoice_value', 14, 2);
            $table->decimal('accepted_value', 14, 2);
            $table->decimal('deduction_defective', 14, 2)->default(0);
            $table->decimal('deduction_rejected', 14, 2)->default(0);
            $table->decimal('deduction_missing', 14, 2)->default(0);
            $table->decimal('final_payable', 14, 2);
            $table->string('vendor_status')->default('pending'); // pending | cleared | hold
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_records');
    }
};
