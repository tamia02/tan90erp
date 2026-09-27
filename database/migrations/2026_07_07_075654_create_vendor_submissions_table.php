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
        Schema::create('vendor_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('po_number');
            $table->string('vendor_name');
            $table->string('invoice_number')->nullable();
            $table->integer('invoice_qty')->nullable();
            $table->string('material')->nullable();
            $table->boolean('has_invoice')->default(false);
            $table->boolean('has_eway_bill')->default(false);
            $table->boolean('has_lr_pod')->default(false);
            $table->string('status')->default('submitted'); // submitted | correction_requested | acknowledged
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_submissions');
    }
};
