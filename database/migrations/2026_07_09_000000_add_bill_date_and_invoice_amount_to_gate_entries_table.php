<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->date('po_bill_date')->nullable()->after('po_number');
            $table->decimal('invoice_amount', 14, 2)->nullable()->after('invoice_qty');
        });
    }

    public function down(): void
    {
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropColumn(['po_bill_date', 'invoice_amount']);
        });
    }
};
