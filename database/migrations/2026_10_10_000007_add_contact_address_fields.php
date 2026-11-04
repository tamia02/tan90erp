<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vendor / customer addresses from Zoho (the contact list carries none, so each
// one is fetched once). Customers only had city/state; a PO delivering to a
// customer needs the full address. *_checked_at = Zoho was asked, so a contact
// with no address in Zoho is not asked again every night.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_masters', function (Blueprint $table) {
            $table->timestamp('zoho_address_checked_at')->nullable();
        });
        Schema::table('tan90_customers', function (Blueprint $table) {
            $table->string('address_building')->nullable()->after('city');
            $table->string('address_street')->nullable()->after('address_building');
            $table->string('address_zip', 20)->nullable()->after('address_street');
            $table->timestamp('zoho_address_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_masters', fn (Blueprint $table) => $table->dropColumn('zoho_address_checked_at'));
        Schema::table('tan90_customers', fn (Blueprint $table) => $table->dropColumn(['address_building', 'address_street', 'address_zip', 'zoho_address_checked_at']));
    }
};
