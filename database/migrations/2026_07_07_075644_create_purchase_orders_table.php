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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number');
            $table->string('po_owner')->nullable();
            $table->string('subject')->nullable();
            $table->string('requisition_number')->nullable();
            $table->string('vendor_name');
            $table->string('contact_name')->nullable();
            $table->date('po_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('Created');
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->decimal('excise_duty', 12, 2)->nullable();
            $table->decimal('sales_commission', 12, 2)->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_building')->nullable();
            $table->string('billing_street')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_zip')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_building')->nullable();
            $table->string('shipping_street')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_zip')->nullable();
            // Order-level, on top of each line's own — mirrors Zoho's Sub
            // Total -> Discount -> Tax -> Adjustment -> Grand Total chain.
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('tax', 12, 2)->nullable();
            $table->decimal('adjustment', 12, 2)->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
