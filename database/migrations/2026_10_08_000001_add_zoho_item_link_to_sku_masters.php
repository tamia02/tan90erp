<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Zoho is the source of truth for Items: keep a stable link to the Zoho item
// (its name can change) plus the Zoho fields we classify by. All nullable, so
// existing SKU Master rows and the GRN flow are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_masters', function (Blueprint $table) {
            $table->string('zoho_item_id', 40)->nullable()->unique()->after('id');
            $table->string('zoho_item_type', 40)->nullable()->after('zoho_item_id'); // inventory | sales | purchases | sales_and_purchases
            $table->boolean('zoho_is_composite')->default(false)->after('zoho_item_type');
            $table->string('zoho_tags')->nullable()->after('zoho_is_composite');
            $table->string('zoho_hsn', 40)->nullable()->after('zoho_tags');
            $table->timestamp('zoho_synced_at')->nullable()->after('zoho_hsn');
        });
    }

    public function down(): void
    {
        Schema::table('sku_masters', function (Blueprint $table) {
            $table->dropUnique(['zoho_item_id']);
            $table->dropColumn(['zoho_item_id', 'zoho_item_type', 'zoho_is_composite', 'zoho_tags', 'zoho_hsn', 'zoho_synced_at']);
        });
    }
};
