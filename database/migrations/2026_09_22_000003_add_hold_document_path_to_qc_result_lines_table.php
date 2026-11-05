<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('qc_result_lines', 'hold_document_path')) {
            Schema::table('qc_result_lines', function (Blueprint $table) {
                $table->string('hold_document_path')->nullable()->after('hold_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('qc_result_lines', 'hold_document_path')) {
            Schema::table('qc_result_lines', fn (Blueprint $table) => $table->dropColumn('hold_document_path'));
        }
    }
};
