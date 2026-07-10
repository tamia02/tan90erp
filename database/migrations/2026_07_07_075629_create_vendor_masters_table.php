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
        Schema::create('vendor_masters', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_name');
            // Kept even though the client's Zoho Vendors module has no GST
            // field — our own gate GST-mismatch check depends on it.
            $table->string('gst_number');
            $table->string('contact_phone');
            $table->string('contact_email')->nullable();
            $table->string('category');
            $table->boolean('active')->default(true);
            $table->string('vendor_owner')->nullable();
            $table->string('website')->nullable();
            $table->string('gl_account')->nullable();
            $table->boolean('email_opt_out')->default(false);
            $table->string('address_country')->nullable();
            $table->string('address_building')->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state')->nullable();
            $table->string('address_zip')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_masters');
    }
};
