<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Zoho's last_modified_time of a composite item when its recipe was last pulled,
// so later pulls only fetch recipes that changed in Zoho.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_masters', function (Blueprint $table) {
            $table->string('zoho_recipe_modified_at', 40)->nullable()->after('zoho_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('sku_masters', function (Blueprint $table) {
            $table->dropColumn('zoho_recipe_modified_at');
        });
    }
};
