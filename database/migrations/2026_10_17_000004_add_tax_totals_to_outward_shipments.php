<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outward_shipments', 'subtotal_value') || ! Schema::hasColumn('outward_shipments', 'tax_total')) {
            Schema::table('outward_shipments', function (Blueprint $table): void {
                if (! Schema::hasColumn('outward_shipments', 'subtotal_value')) {
                    $table->decimal('subtotal_value', 14, 2)->default(0)->after('sales_order_number');
                }
                if (! Schema::hasColumn('outward_shipments', 'tax_total')) {
                    $table->decimal('tax_total', 14, 2)->default(0)->after('subtotal_value');
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately retain these fields: this migration is idempotent for
        // installs that already received the columns through an earlier schema.
    }
};
