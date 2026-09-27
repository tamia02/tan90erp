<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('grn_records', 'vendor_notified_at')) {
                $table->timestamp('vendor_notified_at')->nullable()->after('vendor_responded_by');
            }
            if (! Schema::hasColumn('grn_records', 'vendor_notified_by')) {
                $table->foreignId('vendor_notified_by')->nullable()->after('vendor_notified_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_records', function (Blueprint $table): void {
            if (Schema::hasColumn('grn_records', 'vendor_notified_by')) {
                $table->dropConstrainedForeignId('vendor_notified_by');
            }
            if (Schema::hasColumn('grn_records', 'vendor_notified_at')) {
                $table->dropColumn('vendor_notified_at');
            }
        });
    }
};
