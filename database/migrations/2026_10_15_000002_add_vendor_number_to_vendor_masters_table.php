<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendor_masters', 'vendor_number')) {
            return;
        }

        Schema::table('vendor_masters', function (Blueprint $table): void {
            $table->string('vendor_number', 40)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendor_masters', 'vendor_number')) {
            return;
        }

        Schema::table('vendor_masters', function (Blueprint $table): void {
            $table->dropUnique(['vendor_number']);
            $table->dropColumn('vendor_number');
        });
    }
};
