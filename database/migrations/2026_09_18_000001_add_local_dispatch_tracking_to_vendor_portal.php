<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->string('invoice_document_path')->nullable()->after('has_invoice');
            $table->string('eway_bill_document_path')->nullable()->after('has_eway_bill');
            $table->string('lr_pod_document_path')->nullable()->after('has_lr_pod');
            $table->timestamp('production_started_at')->nullable()->after('status');
            $table->timestamp('dispatched_at')->nullable()->after('production_started_at');
            $table->timestamp('tat_due_at')->nullable()->after('dispatched_at');
        });

        Schema::table('purchase_order_acknowledgements', function (Blueprint $table) {
            $table->timestamp('production_started_at')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_acknowledgements', function (Blueprint $table) {
            $table->dropColumn('production_started_at');
        });

        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_document_path',
                'eway_bill_document_path',
                'lr_pod_document_path',
                'production_started_at',
                'dispatched_at',
                'tat_due_at',
            ]);
        });
    }
};
