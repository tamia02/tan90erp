<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_master_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('source_file', 160);
            $table->string('source_key', 191);
            $table->string('dataset', 80)->index();
            $table->unsignedInteger('source_row_number');
            $table->string('display_name')->nullable()->index();
            $table->string('canonical_type', 40)->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->longText('payload');
            $table->timestamps();
            $table->unique(['source_file', 'source_key'], 'tan90_master_source_rows_source_unique');
            $table->index(['canonical_type', 'canonical_id'], 'tan90_master_source_rows_canonical_idx');
        });

        Schema::create('tan90_master_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_type', 20);
            $table->string('source_address_id', 100);
            $table->string('partner_source_id', 100)->nullable();
            $table->string('canonical_type', 40)->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->string('partner_name')->nullable()->index();
            $table->string('label')->nullable();
            $table->string('attention')->nullable();
            $table->string('address')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->string('country')->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('fax', 100)->nullable();
            $table->string('tax_registration_no', 100)->nullable();
            $table->string('place_of_supply')->nullable();
            $table->string('business_legal_name')->nullable();
            $table->string('business_trade_name')->nullable();
            $table->longText('source_payload');
            $table->timestamps();
            $table->unique(['partner_type', 'source_address_id'], 'tan90_master_addresses_source_unique');
            $table->index(['canonical_type', 'canonical_id'], 'tan90_master_addresses_canonical_idx');
        });

        Schema::create('tan90_master_contact_people', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_type', 20);
            $table->string('source_contact_person_id', 100);
            $table->string('partner_source_id', 100)->nullable();
            $table->string('canonical_type', 40)->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->string('partner_name')->nullable()->index();
            $table->string('salutation', 40)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('mobile_phone', 100)->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->longText('source_payload');
            $table->timestamps();
            $table->unique(['partner_type', 'source_contact_person_id'], 'tan90_master_contact_people_source_unique');
            $table->index(['canonical_type', 'canonical_id'], 'tan90_master_contact_people_canonical_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_master_contact_people');
        Schema::dropIfExists('tan90_master_addresses');
        Schema::dropIfExists('tan90_master_source_rows');
    }
};
