<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'pdf_template')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('pdf_template', 20)->default('standard');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'pdf_template')) {
            Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('pdf_template'));
        }
    }
};
