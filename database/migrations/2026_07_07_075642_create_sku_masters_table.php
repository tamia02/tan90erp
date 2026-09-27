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
        Schema::create('sku_masters', function (Blueprint $table) {
            $table->id();
            $table->string('sku'); // Zoho "Product Name"
            $table->string('category');
            $table->string('unit'); // Zoho "Usage Unit"
            // Tan90-only — doesn't exist in Zoho, drives our own put-away +
            // the gate's "SKU not mapped" check.
            $table->string('default_bin')->nullable();
            $table->boolean('mapped')->default(true);
            $table->string('product_owner')->nullable();
            $table->string('product_code')->nullable();
            $table->boolean('active')->default(true);
            $table->string('vendor_name')->nullable();
            $table->string('manufacturer')->nullable();
            $table->date('sales_start_date')->nullable();
            $table->date('sales_end_date')->nullable();
            $table->date('support_start_date')->nullable();
            $table->date('support_end_date')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('tax', 5, 2)->nullable();
            $table->decimal('commission_rate', 12, 2)->nullable();
            $table->boolean('taxable')->default(true);
            $table->integer('quantity_in_stock')->nullable();
            $table->string('handler')->nullable();
            $table->integer('qty_ordered')->nullable();
            $table->integer('reorder_level')->nullable();
            $table->integer('quantity_in_demand')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_masters');
    }
};
