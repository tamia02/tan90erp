<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_records', function (Blueprint $table) {
            $table->string('vendor_response', 20)->nullable()->after('posted'); // accepted | escalated
            $table->text('vendor_response_note')->nullable()->after('vendor_response');
            $table->string('vendor_cn_dn_path')->nullable()->after('vendor_response_note');
            $table->timestamp('vendor_responded_at')->nullable()->after('vendor_cn_dn_path');
            $table->foreignId('vendor_responded_by')->nullable()->after('vendor_responded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grn_records', function (Blueprint $table) {
            $table->dropForeign(['vendor_responded_by']);
            $table->dropColumn(['vendor_response', 'vendor_response_note', 'vendor_cn_dn_path', 'vendor_responded_at', 'vendor_responded_by']);
        });
    }
};
