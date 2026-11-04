<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Zoho custom field definitions (label, type, required, dropdown options) per
// Zoho module, pulled from Zoho so ERP forms show the same fields and enforce
// the same required ones. purchase_orders.zoho_custom_fields keeps the values
// keyed by Zoho api_name, ready to send to Zoho.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 60);          // Zoho module, e.g. purchaseorder
            $table->string('api_name', 120);       // e.g. cf_purchase_nature
            $table->string('label');
            $table->string('data_type', 40);       // string, date, dropdown, number, ...
            $table->boolean('is_mandatory')->default(false);
            $table->json('options')->nullable();   // dropdown values, in Zoho order
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->string('zoho_field_id', 40)->nullable();
            $table->timestamps();
            $table->unique(['entity', 'api_name']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->json('zoho_custom_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('zoho_custom_fields');
        });
        Schema::dropIfExists('zoho_field_definitions');
    }
};
