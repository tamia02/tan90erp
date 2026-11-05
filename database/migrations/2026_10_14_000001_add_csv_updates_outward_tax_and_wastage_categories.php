<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tan90_data_import_jobs', function (Blueprint $table) {
            $table->unsignedInteger('updated_rows')->default(0)->after('valid_rows');
        });

        Schema::table('outward_shipments', function (Blueprint $table) {
            $table->decimal('subtotal_value', 14, 2)->default(0)->after('to_state');
            $table->decimal('tax_total', 14, 2)->default(0)->after('subtotal_value');
        });
        DB::table('outward_shipments')->update(['subtotal_value' => DB::raw('total_value')]);

        Schema::table('outward_shipment_lines', function (Blueprint $table) {
            $table->decimal('gst_rate', 7, 3)->default(0)->after('value');
            $table->string('tax_type', 20)->nullable()->after('gst_rate');
            $table->decimal('cgst_rate', 7, 3)->default(0)->after('tax_type');
            $table->decimal('sgst_rate', 7, 3)->default(0)->after('cgst_rate');
            $table->decimal('igst_rate', 7, 3)->default(0)->after('sgst_rate');
            $table->decimal('cgst_amount', 14, 2)->default(0)->after('igst_rate');
            $table->decimal('sgst_amount', 14, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 14, 2)->default(0)->after('sgst_amount');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('igst_amount');
            $table->decimal('total_amount', 14, 2)->default(0)->after('tax_amount');
        });
        DB::table('outward_shipment_lines')->update(['total_amount' => DB::raw('value')]);

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            // Keep the wider string so rollback never truncates categorized wastage entries.
            $table->string('type', 32)->change();
        });
        Schema::table('outward_shipment_lines', function (Blueprint $table) {
            $table->dropColumn(['gst_rate', 'tax_type', 'cgst_rate', 'sgst_rate', 'igst_rate', 'cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'total_amount']);
        });
        Schema::table('outward_shipments', function (Blueprint $table) {
            $table->dropColumn(['subtotal_value', 'tax_total']);
        });
        Schema::table('tan90_data_import_jobs', function (Blueprint $table) {
            $table->dropColumn('updated_rows');
        });
    }
};
