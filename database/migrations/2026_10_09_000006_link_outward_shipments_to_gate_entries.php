<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Joins the Guard's outward gate pass (truck) to the Outward Shipment
// (GST documents + stock): the pass is made from a ready shipment, and the
// Guard's "confirm exit" marks the shipment dispatched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->foreignId('outward_shipment_id')->nullable()->after('product_lines')->constrained('outward_shipments')->nullOnDelete();
        });
        Schema::table('outward_shipments', function (Blueprint $table) {
            $table->foreignId('gate_entry_id')->nullable()->after('status')->constrained('gate_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outward_shipments', fn (Blueprint $table) => $table->dropConstrainedForeignId('gate_entry_id'));
        Schema::table('gate_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('outward_shipment_id'));
    }
};
