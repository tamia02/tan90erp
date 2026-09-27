<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_records', function (Blueprint $table) {
            // unchecked | matched | exception — computed by ThreeWayMatchService
            // against the originating PO and GRN, additive to the existing
            // (tested) rate/value computation in GrnPostingService, never
            // replacing it.
            $table->string('match_status', 20)->default('unchecked')->after('final_payable');
            $table->text('match_notes')->nullable()->after('match_status');
        });

        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_record_id')->constrained()->cascadeOnDelete();
            $table->string('vendor_name');
            $table->string('reason');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('issued'); // issued, acknowledged, settled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');

        Schema::table('finance_records', function (Blueprint $table) {
            $table->dropColumn(['match_status', 'match_notes']);
        });
    }
};
