<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tan90_master_addresses', function (Blueprint $table): void {
            $table->text('address')->nullable()->change();
            $table->text('street2')->nullable()->change();
        });

        Schema::table('tan90_customers', function (Blueprint $table): void {
            $table->text('address_building')->nullable()->change();
            $table->text('address_street')->nullable()->change();
        });

        Schema::table('vendor_masters', function (Blueprint $table): void {
            $table->text('address_building')->nullable()->change();
            $table->text('address_street')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep the wider columns on rollback to avoid truncating imported addresses.
    }
};
