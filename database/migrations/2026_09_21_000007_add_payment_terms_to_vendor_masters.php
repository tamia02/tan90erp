<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendor_masters', 'payment_terms')) {
            Schema::table('vendor_masters', function (Blueprint $table): void {
                $table->string('payment_terms')->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendor_masters', 'payment_terms')) {
            Schema::table('vendor_masters', fn (Blueprint $table) => $table->dropColumn('payment_terms'));
        }
    }
};
