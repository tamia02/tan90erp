<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendor_masters', 'tds_section_code')) {
            Schema::table('vendor_masters', function (Blueprint $table): void {
                $table->string('tds_section_code', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Preserve imported section codes if a future rollback is requested.
    }
};
