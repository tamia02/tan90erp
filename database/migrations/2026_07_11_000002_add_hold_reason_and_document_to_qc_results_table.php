<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_results', function (Blueprint $table) {
            $table->text('hold_reason')->nullable()->after('qc_reasons');
            $table->string('hold_document_path')->nullable()->after('hold_reason');
        });
    }

    public function down(): void
    {
        Schema::table('qc_results', function (Blueprint $table) {
            $table->dropColumn(['hold_reason', 'hold_document_path']);
        });
    }
};
