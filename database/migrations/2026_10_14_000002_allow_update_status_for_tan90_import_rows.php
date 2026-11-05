<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite's enum CHECK would otherwise reject the new `update` state.
            Schema::table('tan90_data_import_rows', fn (Blueprint $table) => $table->string('status')->default('valid')->change());
        } else {
            DB::statement("ALTER TABLE tan90_data_import_rows MODIFY status ENUM('valid', 'update', 'invalid', 'duplicate', 'imported') NOT NULL DEFAULT 'valid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tan90_data_import_rows MODIFY status ENUM('valid', 'invalid', 'duplicate', 'imported') NOT NULL DEFAULT 'valid'");
        }
    }
};
