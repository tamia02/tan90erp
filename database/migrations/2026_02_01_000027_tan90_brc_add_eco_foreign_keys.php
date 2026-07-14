<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds the FK constraints for tan90_engineering_change_order_id now that
// tan90_engineering_change_orders exists (mirrors Master Data's own
// "add_scope_foreign_keys" migration pattern for the same forward-reference reason).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tan90_recipe_versions', function (Blueprint $table) {
            $table->foreign('tan90_engineering_change_order_id')
                ->references('id')->on('tan90_engineering_change_orders')
                ->nullOnDelete();
        });

        Schema::table('tan90_bom_versions', function (Blueprint $table) {
            $table->foreign('tan90_engineering_change_order_id')
                ->references('id')->on('tan90_engineering_change_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tan90_recipe_versions', function (Blueprint $table) {
            $table->dropForeign(['tan90_engineering_change_order_id']);
        });

        Schema::table('tan90_bom_versions', function (Blueprint $table) {
            $table->dropForeign(['tan90_engineering_change_order_id']);
        });
    }
};
