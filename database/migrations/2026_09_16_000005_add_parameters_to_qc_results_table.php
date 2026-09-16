<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qc_results', function (Blueprint $table) {
            $table->json('parameters')->nullable()->after('qc_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('qc_results', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
