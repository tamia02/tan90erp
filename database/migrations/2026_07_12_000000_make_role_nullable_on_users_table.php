<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The Tan90 module merge (Master Data / BOM, Recipe & Costing) adds its own
// independent tan90_user_profiles-based role, so a user can now exist with
// only a Tan90 module role and no GRN role at all. `role` was NOT NULL with
// no default, which rejected every Master Data/BOM seeded user outright
// (SQLSTATE 1364). No doctrine/dbal installed, so this uses a raw MODIFY
// instead of ->change() (same pattern as this migration history's other
// nullable-column fixes, e.g. unloading_records.started_at).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY role VARCHAR(255) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL');
    }
};
