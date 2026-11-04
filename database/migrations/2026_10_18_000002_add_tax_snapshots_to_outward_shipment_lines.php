<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'gst_rate' => fn (Blueprint $table) => $table->decimal('gst_rate', 8, 3)->nullable(),
            'tax_type' => fn (Blueprint $table) => $table->string('tax_type', 24)->nullable(),
            'cgst_rate' => fn (Blueprint $table) => $table->decimal('cgst_rate', 8, 3)->nullable(),
            'sgst_rate' => fn (Blueprint $table) => $table->decimal('sgst_rate', 8, 3)->nullable(),
            'igst_rate' => fn (Blueprint $table) => $table->decimal('igst_rate', 8, 3)->nullable(),
            'cgst_amount' => fn (Blueprint $table) => $table->decimal('cgst_amount', 14, 2)->nullable(),
            'sgst_amount' => fn (Blueprint $table) => $table->decimal('sgst_amount', 14, 2)->nullable(),
            'igst_amount' => fn (Blueprint $table) => $table->decimal('igst_amount', 14, 2)->nullable(),
            'tax_amount' => fn (Blueprint $table) => $table->decimal('tax_amount', 14, 2)->nullable(),
            'total_amount' => fn (Blueprint $table) => $table->decimal('total_amount', 14, 2)->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('outward_shipment_lines', $name)) {
                Schema::table('outward_shipment_lines', $definition);
            }
        }
    }

    public function down(): void
    {
        // Deliberately retain these columns. Some installations already had
        // the tax snapshot columns before this migration was added to source;
        // dropping them on rollback would destroy production invoice data.
    }
};
