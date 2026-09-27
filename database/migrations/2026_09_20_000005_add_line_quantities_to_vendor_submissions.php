<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendor_submissions', 'line_quantities')) {
            Schema::table('vendor_submissions', function (Blueprint $table) {
                $table->json('line_quantities')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendor_submissions', 'line_quantities')) {
            Schema::table('vendor_submissions', fn (Blueprint $table) => $table->dropColumn('line_quantities'));
        }
    }
};
