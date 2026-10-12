<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Location-based access (part B): stock per site. Each stock movement and each
// Create Bundle run records its site. Existing dispatches take their outward
// shipment's "from" site; anything else with no site counts at the main site
// (Bhiwandi), and all-site totals stay exactly the same.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->index();
        });
        Schema::table('inventory_productions', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->index();
        });

        foreach (DB::table('outward_shipment_lines as l')->join('outward_shipments as s', 's.id', '=', 'l.outward_shipment_id')
            ->whereNotNull('l.dispatch_id')->whereNotNull('s.from_location_id')->get(['l.dispatch_id', 's.from_location_id']) as $row) {
            DB::table('inventory_movements')->where('dispatch_id', $row->dispatch_id)->update(['location_id' => $row->from_location_id]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropColumn('location_id'));
        Schema::table('inventory_productions', fn (Blueprint $t) => $t->dropColumn('location_id'));
    }
};
