<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('store_exec_verified_by')->nullable()->after('approved_at');
            $table->dateTime('store_exec_verified_at')->nullable()->after('store_exec_verified_by');
            $table->index(['entry_type', 'store_exec_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropIndex(['entry_type', 'store_exec_verified_at']);
            $table->dropColumn(['store_exec_verified_by', 'store_exec_verified_at']);
        });
    }
};
