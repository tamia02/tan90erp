<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Meeting 2026-10-08: PO numbering series by PO category, managed by Admin
// (Master Data > Number Series) and restarting each financial year.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tan90_number_series', function (Blueprint $table) {
            $table->string('current_period')->nullable()->after('reset_policy');
        });

        foreach (['Trading' => 'TR', 'Raw Material' => 'RM', 'PM Packaging Material' => 'PM', 'Others' => 'OT'] as $nature => $code) {
            DB::table('tan90_number_series')->insertOrIgnore([
                'module' => "Purchase Order: {$nature}", 'prefix' => "PO {$code}", 'pattern' => "PO {$code} {FY} {####}",
                'next_number' => 1, 'reset_policy' => 'Yearly', 'preview' => null, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tan90_number_series')->where('module', 'like', 'Purchase Order: %')->delete();
        Schema::table('tan90_number_series', fn (Blueprint $table) => $table->dropColumn('current_period'));
    }
};
