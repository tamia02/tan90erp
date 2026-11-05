<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_process_records', function (Blueprint $table): void {
            $table->decimal('recovered_pcm_qty', 14, 3)->default(0)->after('wastage_qty');
        });
    }

    public function down(): void
    {
        Schema::table('forge_process_records', function (Blueprint $table): void {
            $table->dropColumn('recovered_pcm_qty');
        });
    }
};
