<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unloading_records', function (Blueprint $table) {
            $table->timestamp('allotted_at')->nullable()->after('unloaded_by');
        });

        // started_at is no longer set the moment the record is created —
        // it's now set when "Start Unloading" runs, after the new "Allot"
        // step — so it has to allow null in between. No doctrine/dbal
        // installed, so recreate the column instead of ->change()'ing it.
        Schema::table('unloading_records', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });

        Schema::table('unloading_records', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('allotted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unloading_records', function (Blueprint $table) {
            $table->dropColumn('allotted_at');
        });

        Schema::table('unloading_records', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });

        Schema::table('unloading_records', function (Blueprint $table) {
            $table->timestamp('started_at');
        });
    }
};
