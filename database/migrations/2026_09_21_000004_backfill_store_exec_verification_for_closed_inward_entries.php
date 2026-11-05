<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Historical inward entries were already physically put away by a
        // Store Executive, but the later verification columns were added
        // after those rows were created. Backfill only the unambiguous rows.
        DB::table('gate_entries')
            ->where('entry_type', 'inward')
            ->where('status', 'closed')
            ->whereNotNull('putaway_by')
            ->whereNotNull('putaway_completed_at')
            ->whereNull('store_exec_verified_at')
            ->update([
                'store_exec_verified_by' => DB::raw('putaway_by'),
                'store_exec_verified_at' => DB::raw('putaway_completed_at'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The original values are not recoverable safely; leave historical
        // verification evidence intact if this migration is rolled back.
    }
};
