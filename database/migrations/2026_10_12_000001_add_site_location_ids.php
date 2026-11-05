<?php

use App\Support\SiteLocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Location-based access (part A): a person can be tied to one site (Bhiwandi /
// Chennai / Delhi-Pali). Gate entries and POs get the site id so each site's
// staff see their own. Existing rows are filled from what they already store
// (the Guard's location name, the PO's delivery warehouse). Null = no site =
// visible to everyone, and a user with no site keeps seeing everything.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->index();
        });
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->index();
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->index();
        });

        foreach (DB::table('gate_entries')->whereNotNull('location')->distinct()->pluck('location') as $name) {
            if ($id = SiteLocation::idForName($name)) {
                DB::table('gate_entries')->where('location', $name)->update(['location_id' => $id]);
            }
        }
        foreach (DB::table('purchase_orders')->whereNotNull('warehouse_location')->where('warehouse_location', '!=', '')->distinct()->pluck('warehouse_location') as $name) {
            if ($id = SiteLocation::idForName($name)) {
                DB::table('purchase_orders')->where('warehouse_location', $name)->update(['location_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'gate_entries', 'purchase_orders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('location_id'));
        }
    }
};
