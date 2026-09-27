<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * po_number/vendor_name (strings, not FKs) matches the loose-coupling
 * convention the rest of this pipeline already uses — VendorSubmission,
 * GateEntry etc. all key on po_number/vendor_name rather than purchase_order_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('po_number');
            $table->string('vendor_name');
            $table->boolean('accepted');
            $table->text('remarks')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            // One live acknowledgement per PO — re-acknowledging (e.g. changing
            // Decline to Accept after a revised PO) updates this row rather than
            // stacking a new one.
            $table->unique('po_number');
        });

        Schema::create('supplier_claims', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->nullable();
            $table->string('vendor_name');
            $table->string('description', 2000);
            $table->string('status', 20)->default('open'); // open, reviewing, resolved, rejected
            $table->text('resolution_notes')->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_claims');
        Schema::dropIfExists('purchase_order_acknowledgements');
    }
};
