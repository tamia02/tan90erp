<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dateTime('visitor_checked_in_at')->nullable()->after('final_bin');
            $table->dateTime('visitor_checked_out_at')->nullable()->after('visitor_checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn(['visitor_checked_in_at', 'visitor_checked_out_at']);
        });
    }
};
