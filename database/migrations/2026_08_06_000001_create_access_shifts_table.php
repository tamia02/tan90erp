<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Closes the last structural gap in the org hierarchy - the People/Access/
// Organisation spec is "company, verticals, plants, teams, shifts" and
// everything except shifts already existed (unit already covers the
// plant/location level).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('access_teams')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('access_units')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['team_id', 'code']);
        });

        Schema::table('access_positions', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('team_id')->constrained('access_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
        Schema::dropIfExists('access_shifts');
    }
};
