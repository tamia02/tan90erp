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
        Schema::table('qc_results', function (Blueprint $table) {
            // null | pending (vendor notified, awaiting action) | initiated (vendor actioned)
            $table->string('return_status')->nullable()->after('qc_reasons');
            $table->timestamp('return_requested_at')->nullable()->after('return_status');
            $table->timestamp('return_initiated_at')->nullable()->after('return_requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qc_results', function (Blueprint $table) {
            $table->dropColumn(['return_status', 'return_requested_at', 'return_initiated_at']);
        });
    }
};
