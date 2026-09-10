<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->string('bill_document_path')->nullable()->after('bill_scanned');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn('bill_document_path');
        });
    }
};
