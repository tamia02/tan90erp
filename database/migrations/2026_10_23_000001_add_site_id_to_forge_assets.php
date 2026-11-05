<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forge_machines', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('plant')->constrained('tan90_locations')->nullOnDelete();
        });

        Schema::table('forge_freezers', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('plant')->constrained('tan90_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forge_freezers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::table('forge_machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
