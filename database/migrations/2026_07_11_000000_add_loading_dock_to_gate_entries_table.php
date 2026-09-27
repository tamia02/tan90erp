<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->string('loading_dock')->nullable()->after('status');
            $table->timestamp('dock_assigned_at')->nullable()->after('loading_dock');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn(['loading_dock', 'dock_assigned_at']);
        });
    }
};
