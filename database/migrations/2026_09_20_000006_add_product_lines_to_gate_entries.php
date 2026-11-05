<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gate_entries', 'product_lines')) {
            Schema::table('gate_entries', function (Blueprint $table) {
                $table->json('product_lines')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gate_entries', 'product_lines')) {
            Schema::table('gate_entries', fn (Blueprint $table) => $table->dropColumn('product_lines'));
        }
    }
};
