<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->foreignId('putaway_by')->nullable()->after('exited_at')->constrained('users')->nullOnDelete();
            $table->timestamp('putaway_completed_at')->nullable()->after('putaway_by');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('putaway_by');
            $table->dropColumn('putaway_completed_at');
        });
    }
};
