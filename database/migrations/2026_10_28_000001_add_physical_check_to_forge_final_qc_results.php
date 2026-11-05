<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_final_qc_results', function (Blueprint $table): void {
            $table->string('physical_check', 20)->nullable()->after('pressure_test');
        });
    }

    public function down(): void
    {
        Schema::table('forge_final_qc_results', fn (Blueprint $table) => $table->dropColumn('physical_check'));
    }
};
