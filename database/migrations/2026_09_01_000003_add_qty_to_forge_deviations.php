<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The affected quantity, previously absent — needed as the target_qty when a
 * "rework" disposition spins up an actual rework work order (spec: "Create
 * WO-2001 to rework 42 scrapped items").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_deviations', function (Blueprint $table) {
            $table->decimal('qty', 14, 3)->nullable()->after('description');
            $table->string('uom', 20)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('forge_deviations', function (Blueprint $table) {
            $table->dropColumn(['qty', 'uom']);
        });
    }
};
