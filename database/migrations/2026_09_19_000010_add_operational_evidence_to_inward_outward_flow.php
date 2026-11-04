<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gate_entries', 'loading_started_at')) {
            Schema::table('gate_entries', function (Blueprint $table) {
                $table->timestamp('loading_started_at')->nullable()->after('dock_assigned_at');
            });
        }

        if (! Schema::hasColumn('unloading_records', 'packing_unit')) {
            Schema::table('unloading_records', function (Blueprint $table) {
                $table->string('packing_unit')->nullable()->after('box_count');
                $table->string('pod_document_path')->nullable()->after('pod_lr_ref');
            });
        }

        if (! Schema::hasColumn('qc_results', 'invoice_amount')) {
            Schema::table('qc_results', function (Blueprint $table) {
                $table->decimal('invoice_amount', 14, 2)->nullable()->after('invoice_qty');
                $table->decimal('unit_price', 14, 4)->nullable()->after('invoice_amount');
                $table->json('evidence_images')->nullable()->after('documents_checked');
                $table->string('evidence_image_type')->nullable()->after('evidence_images');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gate_entries', 'loading_started_at')) {
            Schema::table('gate_entries', fn (Blueprint $table) => $table->dropColumn('loading_started_at'));
        }

        if (Schema::hasColumn('unloading_records', 'packing_unit')) {
            Schema::table('unloading_records', function (Blueprint $table) {
                $table->dropColumn(['packing_unit', 'pod_document_path']);
            });
        }

        if (Schema::hasColumn('qc_results', 'invoice_amount')) {
            Schema::table('qc_results', function (Blueprint $table) {
                $table->dropColumn(['invoice_amount', 'unit_price', 'evidence_images', 'evidence_image_type']);
            });
        }
    }
};
