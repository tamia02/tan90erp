<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->date('invoice_due_date')->nullable()->after('invoice_date');
            $table->decimal('invoice_amount', 14, 2)->nullable()->after('invoice_qty');
            $table->index(['vendor_name', 'po_number']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_submissions', function (Blueprint $table) {
            $table->dropIndex(['vendor_name', 'po_number']);
            $table->dropColumn(['invoice_date', 'invoice_due_date', 'invoice_amount']);
        });
    }
};
