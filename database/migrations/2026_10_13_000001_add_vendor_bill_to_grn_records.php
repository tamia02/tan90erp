<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client meeting 2026-10-13: after QC and GRN, tag the vendor's bill (number,
// date, amount, file) to the GRN so the paper trail is complete for Finance.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_records', function (Blueprint $table) {
            $table->string('vendor_bill_number')->nullable();
            $table->date('vendor_bill_date')->nullable();
            $table->decimal('vendor_bill_amount', 14, 2)->nullable();
            $table->string('vendor_bill_path')->nullable();
            $table->unsignedBigInteger('vendor_bill_tagged_by')->nullable();
            $table->timestamp('vendor_bill_tagged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('grn_records', fn (Blueprint $t) => $t->dropColumn(['vendor_bill_number', 'vendor_bill_date', 'vendor_bill_amount', 'vendor_bill_path', 'vendor_bill_tagged_by', 'vendor_bill_tagged_at']));
    }
};
