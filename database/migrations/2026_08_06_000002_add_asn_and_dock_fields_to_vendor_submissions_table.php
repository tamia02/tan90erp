<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Turns vendor_submissions into a real Advance Shipping Notice: a vendor
// already tells Guard what's coming via this table (invoice/PO/material)
// before the truck shows up - it just never captured WHEN it's arriving or
// which dock it should use, so there was no dock scheduling step at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->timestamp('expected_arrival_at')->nullable()->after('material');
            $table->string('vehicle_number')->nullable()->after('expected_arrival_at');
            $table->string('dock_number')->nullable()->after('vehicle_number');
            $table->timestamp('dock_scheduled_at')->nullable()->after('dock_number');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->dropColumn(['expected_arrival_at', 'vehicle_number', 'dock_number', 'dock_scheduled_at']);
        });
    }
};
