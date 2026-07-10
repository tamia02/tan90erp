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
        Schema::create('gate_entries', function (Blueprint $table) {
            $table->id();
            $table->string('gate_no');
            $table->string('entry_type'); // inward | outward | visitor | loading
            $table->string('po_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_gst')->nullable();
            $table->string('invoice_number')->nullable();
            $table->integer('invoice_qty')->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->string('material')->nullable();
            $table->string('vehicle_number');
            $table->string('driver_name');
            $table->string('transporter')->nullable();
            $table->string('location');
            $table->string('gps')->nullable();
            $table->boolean('bill_scanned')->default(false);
            $table->text('remarks')->nullable();
            $table->string('status')->default('pending_validation');
            $table->timestamp('sla_deadline');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_entries');
    }
};
