<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dateTime('loading_window_start')->nullable()->after('dock_assigned_at');
            $table->dateTime('loading_window_end')->nullable()->after('loading_window_start');
            $table->date('expected_delivery_date')->nullable()->after('loading_window_end');
            $table->boolean('dispatch_documents_shared')->default(false)->after('expected_delivery_date');
            $table->dateTime('loaded_at')->nullable()->after('dispatch_documents_shared');
            $table->dateTime('exited_at')->nullable()->after('loaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn(['loading_window_start', 'loading_window_end', 'expected_delivery_date', 'dispatch_documents_shared', 'loaded_at', 'exited_at']);
        });
    }
};
