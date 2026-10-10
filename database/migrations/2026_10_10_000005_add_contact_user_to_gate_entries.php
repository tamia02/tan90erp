<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Meeting whom": the staff member the driver has come to see, so the Guard can call them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->foreignId('contact_user_id')->nullable()->after('driver_name')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_user_id');
        });
    }
};
