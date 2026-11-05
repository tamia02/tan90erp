<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Meeting 2026-10-08: Admin adds custom fields to items (e.g. Cost Price) -
// Number, Text or Drop-down. Values live on the inventory item.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('field_key')->unique();
            $table->string('label');
            $table->enum('input_type', ['text', 'number', 'dropdown'])->default('text');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('custom_fields'));
        Schema::dropIfExists('item_custom_fields');
    }
};
