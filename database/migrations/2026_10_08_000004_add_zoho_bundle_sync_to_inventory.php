<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Create Bundle" -> Zoho Bundle. Zoho bundles belong to a warehouse/location,
// so keep the client's Zoho locations, and record per production whether it
// reached Zoho (sent / failed + reason / skipped) so failures can be retried.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_locations', function (Blueprint $table) {
            $table->id();
            $table->string('zoho_location_id', 40)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('inventory_productions', function (Blueprint $table) {
            $table->string('zoho_location_id', 40)->nullable()->after('notes');
            $table->string('zoho_status', 20)->nullable()->after('zoho_location_id'); // sent | failed | skipped
            $table->string('zoho_bundle_id', 40)->nullable()->after('zoho_status');
            $table->text('zoho_error')->nullable()->after('zoho_bundle_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_productions', function (Blueprint $table) {
            $table->dropColumn(['zoho_location_id', 'zoho_status', 'zoho_bundle_id', 'zoho_error']);
        });
        Schema::dropIfExists('zoho_locations');
    }
};
