<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_mixing_records', function (Blueprint $table): void {
            $table->string('wastage_type', 20)->nullable()->after('wastage_qty');
        });
    }

    public function down(): void
    {
        Schema::table('forge_mixing_records', function (Blueprint $table): void {
            $table->dropColumn('wastage_type');
        });
    }
};
