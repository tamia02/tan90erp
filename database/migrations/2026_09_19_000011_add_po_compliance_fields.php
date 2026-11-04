<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'reference_number')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('reference_number')->nullable();
                $table->string('payment_terms')->nullable();
                $table->date('valid_upto')->nullable();
                $table->string('purchase_nature')->nullable();
                $table->string('shipment_preference')->nullable();
                $table->boolean('reverse_charge')->default(false);
                $table->string('tax_level')->nullable();
                $table->string('warehouse_location')->nullable();
                $table->json('header_custom_fields')->nullable();
                $table->json('attachments')->nullable();
            });
        }

        if (! Schema::hasColumn('purchase_order_lines', 'hsn_sac')) {
            Schema::table('purchase_order_lines', function (Blueprint $table) {
                $table->string('hsn_sac')->nullable()->after('product');
                $table->json('custom_fields')->nullable()->after('tax');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'reference_number')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropColumn(['reference_number', 'payment_terms', 'valid_upto', 'purchase_nature', 'shipment_preference', 'reverse_charge', 'tax_level', 'warehouse_location', 'header_custom_fields', 'attachments']);
            });
        }

        if (Schema::hasColumn('purchase_order_lines', 'hsn_sac')) {
            Schema::table('purchase_order_lines', function (Blueprint $table) {
                $table->dropColumn(['hsn_sac', 'custom_fields']);
            });
        }
    }
};
