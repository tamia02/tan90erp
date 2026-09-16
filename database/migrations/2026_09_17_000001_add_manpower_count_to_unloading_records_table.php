<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unloading_records', function (Blueprint $table) {
            $table->unsignedInteger('manpower_count')->nullable()->after('staging_area');
        });
    }

    public function down(): void
    {
        Schema::table('unloading_records', function (Blueprint $table) {
            $table->dropColumn('manpower_count');
        });
    }
};
