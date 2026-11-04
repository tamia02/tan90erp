<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outward_shipment_lines', function (Blueprint $table) {
            $table->foreignId('packaging_type_id')->nullable()->after('item_id')->constrained('tan90_packaging_types')->nullOnDelete();
            $table->unsignedInteger('packaging_quantity')->nullable()->after('packaging_type_id');
            $table->string('box_size', 16)->nullable()->after('packaging_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('outward_shipment_lines', function (Blueprint $table) {
            $table->dropForeign(['packaging_type_id']);
            $table->dropColumn(['packaging_type_id', 'packaging_quantity', 'box_size']);
        });
    }
};
