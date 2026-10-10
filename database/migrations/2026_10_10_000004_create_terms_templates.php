<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client 2026-10-10: Terms & Conditions and Notes differ by vendor / customer
// category (and sometimes per vendor / customer). One template per category
// (vendor: Zoho "Purchase Nature", customer: Zoho "Business Vertical"), a
// default per side (category null), and an optional template per party.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('party_type', 20); // vendor | customer
            $table->string('category')->nullable(); // null = default for that side
            $table->string('name');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->string('source', 20)->default('manual'); // manual | zoho
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['party_type', 'category', 'active']);
        });
        Schema::table('vendor_masters', function (Blueprint $table) {
            $table->foreignId('terms_template_id')->nullable()->constrained('terms_templates')->nullOnDelete();
        });
        Schema::table('tan90_customers', function (Blueprint $table) {
            $table->foreignId('terms_template_id')->nullable()->constrained('terms_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tan90_customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('terms_template_id'));
        Schema::table('vendor_masters', fn (Blueprint $table) => $table->dropConstrainedForeignId('terms_template_id'));
        Schema::dropIfExists('terms_templates');
    }
};
